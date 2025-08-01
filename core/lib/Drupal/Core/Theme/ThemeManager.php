<?php

namespace Drupal\Core\Theme;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\StackedRouteMatchInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Template\AttributeHelper;
use Drupal\Core\Utility\CallableResolver;

/**
 * Provides the default implementation of a theme manager.
 */
class ThemeManager implements ThemeManagerInterface {

  /**
   * The theme negotiator.
   *
   * @var \Drupal\Core\Theme\ThemeNegotiatorInterface
   */
  protected $themeNegotiator;

  /**
   * The theme registry used to render an output.
   *
   * @var \Drupal\Core\Theme\Registry
   */
  protected $themeRegistry;

  /**
   * Contains the current active theme.
   *
   * @var \Drupal\Core\Theme\ActiveTheme
   */
  protected $activeTheme;

  /**
   * The theme initialization.
   *
   * @var \Drupal\Core\Theme\ThemeInitializationInterface
   */
  protected $themeInitialization;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The app root.
   *
   * @var string
   */
  protected $root;

  /**
   * Default variables.
   *
   * @var array|null
   */
  protected ?array $defaultVariables = NULL;

  /**
   * Constructs a new ThemeManager object.
   *
   * @param string $root
   *   The app root.
   * @param \Drupal\Core\Theme\ThemeNegotiatorInterface $theme_negotiator
   *   The theme negotiator.
   * @param \Drupal\Core\Theme\ThemeInitializationInterface $theme_initialization
   *   The theme initialization.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Utility\CallableResolver $callableResolver
   *   The callable resolver.
   */
  public function __construct($root, ThemeNegotiatorInterface $theme_negotiator, ThemeInitializationInterface $theme_initialization, ModuleHandlerInterface $module_handler, protected CallableResolver $callableResolver) {
    $this->root = $root;
    $this->themeNegotiator = $theme_negotiator;
    $this->themeInitialization = $theme_initialization;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Sets the theme registry.
   *
   * @param \Drupal\Core\Theme\Registry $theme_registry
   *   The theme registry.
   *
   * @return $this
   */
  public function setThemeRegistry(Registry $theme_registry) {
    $this->themeRegistry = $theme_registry;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveTheme(?RouteMatchInterface $route_match = NULL) {
    if (!isset($this->activeTheme)) {
      $this->initTheme($route_match);
    }
    return $this->activeTheme;
  }

  /**
   * {@inheritdoc}
   */
  public function hasActiveTheme() {
    return isset($this->activeTheme);
  }

  /**
   * {@inheritdoc}
   */
  public function resetActiveTheme() {
    $this->activeTheme = NULL;
    $this->defaultVariables = NULL;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setActiveTheme(ActiveTheme $active_theme) {
    $this->activeTheme = $active_theme;
    if ($active_theme) {
      $this->themeInitialization->loadActiveTheme($active_theme);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function render($hook, array $variables) {
    static $default_attributes;

    $active_theme = $this->getActiveTheme();

    $theme_registry = $this->themeRegistry->getRuntime();

    // If an array of hook candidates were passed, use the first one that has an
    // implementation.
    if (is_array($hook)) {
      foreach ($hook as $candidate) {
        if ($theme_registry->has($candidate)) {
          break;
        }
      }
      $hook = $candidate;
    }
    // Save the original theme hook, so it can be supplied to theme variable
    // preprocess callbacks.
    $original_hook = $hook;

    // If there's no implementation, check for more generic fallbacks.
    // If there's still no implementation, log an error and return an empty
    // string.
    if (!$theme_registry->has($hook)) {
      // Iteratively strip everything after the last '__' delimiter, until an
      // implementation is found.
      while ($pos = strrpos($hook, '__')) {
        $hook = substr($hook, 0, $pos);
        if ($theme_registry->has($hook)) {
          break;
        }
      }
      if (!$theme_registry->has($hook)) {
        // Only log a message when not trying theme suggestions ($hook being an
        // array).
        if (!isset($candidate)) {
          \Drupal::logger('theme')->warning('Theme hook %hook not found.', ['%hook' => $hook]);
        }
        // There is no theme implementation for the hook passed. Return FALSE so
        // the function calling
        // \Drupal\Core\Theme\ThemeManagerInterface::render() can differentiate
        // between a hook that exists and renders an empty string, and a hook
        // that is not implemented.
        return FALSE;
      }
    }

    $info = $theme_registry->get($hook);
    $invoke_map = $theme_registry->getPreprocessInvokes();
    if (isset($info['deprecated'])) {
      @trigger_error($info['deprecated'], E_USER_DEPRECATED);
    }

    // If a renderable array is passed as $variables, then set $variables to
    // the arguments expected by the theme function.
    if (isset($variables['#theme']) || isset($variables['#theme_wrappers'])) {
      $element = $variables;
      $variables = [];
      if (isset($info['variables'])) {
        foreach (array_keys($info['variables']) as $name) {
          if (\array_key_exists("#$name", $element)) {
            $variables[$name] = $element["#$name"];
          }
        }
      }
      else {
        $variables[$info['render element']] = $element;
        // Give a hint to render engines to prevent infinite recursion.
        $variables[$info['render element']]['#render_children'] = TRUE;
      }
    }

    // Merge in argument defaults.
    if (!empty($info['variables'])) {
      $variables += $info['variables'];
    }
    elseif (!empty($info['render element'])) {
      $variables += [$info['render element'] => []];
    }
    // Supply original caller info.
    $variables += [
      'theme_hook_original' => $original_hook,
    ];

    $suggestions = $this->buildThemeHookSuggestions($hook, $info['base hook'] ?? '', $variables);

    // Check if each suggestion exists in the theme registry, and if so,
    // use it instead of the base hook. For example, a function may use
    // '#theme' => 'node', but a module can add 'node__article' as a suggestion
    // via hook_theme_suggestions_HOOK_alter(), enabling a theme to have
    // an alternate template file for article nodes.
    foreach (array_reverse($suggestions) as $suggestion) {
      if ($theme_registry->has($suggestion)) {
        $info = $theme_registry->get($suggestion);
        break;
      }
    }

    // Include a file if the variable preprocessor is held elsewhere.
    if (!empty($info['includes'])) {
      foreach ($info['includes'] as $include_file) {
        include_once $this->root . '/' . $include_file;
      }
    }

    // Invoke the variable preprocessors, if any.
    if (isset($info['base hook'])) {
      $base_hook = $info['base hook'];
      $base_hook_info = $theme_registry->get($base_hook);
      // Include files required by the base hook, since its variable
      // preprocessors might reside there.
      if (!empty($base_hook_info['includes'])) {
        foreach ($base_hook_info['includes'] as $include_file) {
          include_once $this->root . '/' . $include_file;
        }
      }
      if (isset($base_hook_info['preprocess functions'])) {
        // Set a variable for the 'theme_hook_suggestion'. This is used to
        // maintain backwards compatibility with template engines.
        $theme_hook_suggestion = $hook;
      }
    }

    // Set default variables before preprocess hooks.
    $variables += $this->getDefaultTemplateVariables();

    // When theming a render element, merge its #attributes into
    // $variables['attributes'].
    if (isset($info['render element'])) {
      $key = $info['render element'];
      if (isset($variables[$key]['#attributes'])) {
        $variables['attributes'] = AttributeHelper::mergeCollections($variables['attributes'], $variables[$key]['#attributes']);
      }
    }

    // Invoke initial preprocess callbacks.
    if (!empty($info['initial preprocess'])) {
      $callable = $info['initial preprocess'];
      try {
        if (!is_callable($callable)) {
          $callable = $this->callableResolver->getCallableFromDefinition($callable);
        }
        $callable($variables, $hook, $info);
      }
      catch (\InvalidArgumentException $e) {
        \Drupal::logger('theme')->warning('Preprocess callback is not valid: %error.', ['%error' => $e->getMessage()]);
      }
    }

    $invoke_preprocess_callback = function (mixed $preprocessor_function) use ($invoke_map, &$variables, $hook, $info): mixed {
      // Preprocess hooks are stored as strings resembling functions.
      // This is for backwards compatibility and may represent OOP
      // implementations as well.
      if (is_string($preprocessor_function) && isset($invoke_map[$preprocessor_function])) {
        // Invoke module preprocess functions.
        $this->moduleHandler->invoke(... $invoke_map[$preprocessor_function], args: [&$variables, $hook, $info]);
      }
      // Invoke preprocess callbacks that are not in the invoke map, such as
      // those from themes or an alter hook.
      elseif (is_callable($preprocessor_function)) {
        call_user_func_array($preprocessor_function, [&$variables, $hook, $info]);
      }
      return $variables;
    };

    // Global preprocess functions are always called, after initial and
    // template preprocess and before regular module and theme preprocess
    // callbacks. template preprocess callbacks are deprecated but still
    // supported, so they need to be called before the first non-template
    // preprocess callback, and if that doesn't happen, after the loop.
    $global_preprocess = $theme_registry->getGlobalPreprocess();
    $global_preprocess_called = FALSE;

    // Invoke preprocess hooks.
    if (isset($info['preprocess functions'])) {
      foreach ($info['preprocess functions'] as $preprocessor_function) {
        // If global preprocess functions have not been called yet and this is
        // not a template preprocess function, invoke them now.
        if (!$global_preprocess_called && is_string($preprocessor_function) && !str_starts_with($preprocessor_function, 'template_')) {
          $global_preprocess_called = TRUE;
          foreach ($global_preprocess as $global_preprocess_callback) {
            $invoke_preprocess_callback($global_preprocess_callback);
          }
        }
        $invoke_preprocess_callback($preprocessor_function);
      }
    }

    // If global process hasn't been invoked yet, do that now.
    if (!$global_preprocess_called) {
      foreach ($global_preprocess as $global_preprocess_callback) {
        $invoke_preprocess_callback($global_preprocess_callback);
      }
    }

    // Allow theme preprocess functions to set $variables['#attached'] and
    // $variables['#cache'] and use them like the corresponding element
    // properties on render arrays. This is the officially supported
    // method of attaching bubbleable metadata from preprocess functions.
    // Assets attached here should be associated with the template
    // that we are preprocessing variables for.
    $preprocess_bubbleable = [];
    foreach (['#attached', '#cache'] as $key) {
      if (isset($variables[$key])) {
        $preprocess_bubbleable[$key] = $variables[$key];
      }
    }
    // We do not allow preprocess functions to define cacheable elements.
    unset($preprocess_bubbleable['#cache']['keys']);
    if ($preprocess_bubbleable) {
      // @todo Inject the Renderer in https://www.drupal.org/node/2529438.
      \Drupal::service('renderer')->render($preprocess_bubbleable);
    }

    // Generate the output using a template.
    $render_function = 'twig_render_template';
    $extension = '.html.twig';

    // The theme engine may use a different extension and a different
    // renderer.
    $theme_engine = $active_theme->getEngine();
    if (isset($theme_engine)) {
      if ($info['type'] != 'module') {
        if (function_exists($theme_engine . '_render_template')) {
          $render_function = $theme_engine . '_render_template';
        }
        $extension_function = $theme_engine . '_extension';
        if (function_exists($extension_function)) {
          $extension = $extension_function();
        }
      }
    }

    if (!isset($default_attributes)) {
      $default_attributes = new Attribute();
    }
    foreach (['attributes', 'title_attributes', 'content_attributes'] as $key) {
      if (isset($variables[$key]) && !($variables[$key] instanceof Attribute)) {
        if ($variables[$key]) {
          $variables[$key] = new Attribute($variables[$key]);
        }
        else {
          // Create empty attributes.
          $variables[$key] = clone $default_attributes;
        }
      }
    }

    // Render the output using the template file.
    $template_file = $info['template'] . $extension;
    if (isset($info['path'])) {
      $template_file = $info['path'] . '/' . $template_file;
    }
    // Add the theme suggestions to the variables array just before rendering
    // the template for backwards compatibility with template engines.
    $variables['theme_hook_suggestions'] = $suggestions;
    // For backwards compatibility, pass 'theme_hook_suggestion' on to the
    // template engine. This is only set when calling a direct suggestion like
    // '#theme' => 'menu__shortcut_default' when the template exists in the
    // current theme.
    if (isset($theme_hook_suggestion)) {
      $variables['theme_hook_suggestion'] = $theme_hook_suggestion;
    }
    $output = $render_function($template_file, $variables);
    return ($output instanceof MarkupInterface) ? $output : (string) $output;
  }

  /**
   * Builds theme hook suggestions for a theme hook with variables.
   *
   * @param string $hook
   *   Theme hook that was called.
   * @param string $info_base_hook
   *   Theme registry info for $hook['base hook'] key or empty string.
   * @param array $variables
   *   Theme variables that were passed along with the call.
   *
   * @return string[]
   *   Suggested theme hook names to use instead of $hook, in the order of
   *   ascending specificity.
   *   The caller will pick the last of those suggestions that has a known theme
   *   registry entry.
   *
   * @internal
   *   This method may change at any time. It is not for use outside this class.
   */
  protected function buildThemeHookSuggestions(string $hook, string $info_base_hook, array &$variables): array {
    // Set base hook for later use. For example if '#theme' => 'node__article'
    // is called, we run hook_theme_suggestions_node_alter() rather than
    // hook_theme_suggestions_node__article_alter(), and also pass in the base
    // hook as the last parameter to the suggestions alter hooks.
    $base_theme_hook = $info_base_hook ?: $hook;

    // Invoke hook_theme_suggestions_HOOK().
    $suggestions = $this->moduleHandler->invokeAll('theme_suggestions_' . $base_theme_hook, [$variables]);
    // If the theme implementation was invoked with a direct theme suggestion
    // like '#theme' => 'node__article', add it to the suggestions array before
    // invoking suggestion alter hooks.
    if ($info_base_hook) {
      $suggestions[] = $hook;
    }

    // Invoke hook_theme_suggestions_alter() and
    // hook_theme_suggestions_HOOK_alter().
    $hooks = [
      'theme_suggestions',
      'theme_suggestions_' . $base_theme_hook,
    ];
    $this->moduleHandler->alter($hooks, $suggestions, $variables, $base_theme_hook);
    $this->alter($hooks, $suggestions, $variables, $base_theme_hook);

    return $suggestions;
  }

  /**
   * Initializes the active theme for a given route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  protected function initTheme(?RouteMatchInterface $route_match = NULL) {
    // Determine the active theme for the theme negotiator service. This
    // includes the default theme as well as really specific ones like the ajax
    // base theme.
    if (!$route_match) {
      $route_match = \Drupal::routeMatch();
    }
    if ($route_match instanceof StackedRouteMatchInterface) {
      $route_match = $route_match->getMasterRouteMatch();
    }
    $theme = $this->themeNegotiator->determineActiveTheme($route_match);
    $this->activeTheme = $this->themeInitialization->initTheme($theme);
  }

  /**
   * {@inheritdoc}
   *
   * @todo Should we cache some of these information?
   */
  public function alterForTheme(ActiveTheme $theme, $type, &$data, &$context1 = NULL, &$context2 = NULL) {
    // Most of the time, $type is passed as a string, so for performance,
    // normalize it to that. When passed as an array, usually the first item in
    // the array is a generic type, and additional items in the array are more
    // specific variants of it, as in the case of ['form', 'form_FORM_ID'].
    if (is_array($type)) {
      $extra_types = $type;
      $type = array_shift($extra_types);
      // Allow if statements in this function to use the faster isset() rather
      // than !empty() both when $type is passed as a string, or as an array
      // with one item.
      if (empty($extra_types)) {
        unset($extra_types);
      }
    }

    $theme_keys = array_reverse(array_keys($theme->getBaseThemeExtensions()));
    $theme_keys[] = $theme->getName();
    $functions = [];
    foreach ($theme_keys as $theme_key) {
      $function = $theme_key . '_' . $type . '_alter';
      if (function_exists($function)) {
        $functions[] = $function;
      }
      if (isset($extra_types)) {
        foreach ($extra_types as $extra_type) {
          $function = $theme_key . '_' . $extra_type . '_alter';
          if (function_exists($function)) {
            $functions[] = $function;
          }
        }
      }
    }

    foreach ($functions as $function) {
      $function($data, $context1, $context2);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alter($type, &$data, &$context1 = NULL, &$context2 = NULL) {
    $theme = $this->getActiveTheme();
    $this->alterForTheme($theme, $type, $data, $context1, $context2);
  }

  /**
   * Returns default template variables.
   *
   * These are set for every template before template preprocessing hooks.
   *
   * See the @link themeable Default theme implementations topic @endlink for
   * details.
   *
   * @return array
   *   An array of default template variables.
   *
   * @internal
   */
  public function getDefaultTemplateVariables(): array {
    if (!isset($this->defaultVariables)) {
      // Variables that don't depend on a database connection.
      $this->defaultVariables = [
        'attributes' => [],
        'title_attributes' => [],
        'content_attributes' => [],
        'title_prefix' => [],
        'title_suffix' => [],
        'db_is_active' => !defined('MAINTENANCE_MODE'),
        'is_admin' => FALSE,
        'logged_in' => FALSE,
      ];

      // Give modules a chance to alter default template variables.
      $this->moduleHandler->alter('template_preprocess_default_variables', $this->defaultVariables);
      // Tell all templates where they are located.
      $this->defaultVariables['directory'] = $this->getActiveTheme()->getPath();
    }
    return $this->defaultVariables;
  }

}
