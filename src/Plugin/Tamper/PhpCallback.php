<?php

namespace Drupal\feeds_tamper_php\Plugin\Tamper;

use Drupal\tamper\TamperBase;
use Drupal\tamper\TamperableItemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Applies custom PHP code to a field value.
 *
 * @Tamper(
 *   id = "php_callback",
 *   label = @Translation("PHP callback"),
 *   description = @Translation("Process the value with a custom PHP snippet."),
 *   category = "Other"
 * )
 */
class PhpCallback extends TamperBase
{

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function tamper($data, ?TamperableItemInterface $item = NULL)
  {
    if (!empty($this->configuration['php_code'])) {
      try {
        $php_code = $this->configuration['php_code'];
        $item_data = $item ? $item->getSource() : [];

        // Create a safer execution context
        $closure = function ($value, $item_data) use ($php_code) {
          return eval($php_code);
        };

        $result = $closure($data, $item_data);
        return $result !== null ? $result : $data;
      } catch (\Throwable $e) {
        \Drupal::logger('feeds_tamper_php')->error('PHP Tamper error: @message', ['@message' => $e->getMessage()]);
        return $data;
      }
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration()
  {
    return ['php_code' => 'return $value;'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form['php_code'] = [
      '#type' => 'textarea',
      '#title' => $this->t('PHP code'),
      '#default_value' => $this->configuration['php_code'] ?? 'return $value;',
      '#description' => $this->t('Enter PHP code. Use $value for the current field value and $item_data for all item mappings. Must return a value. Example: return strtoupper($value);'),
      '#rows' => 10,
      '#required' => TRUE,
    ];

    $form['help'] = [
      '#type' => 'details',
      '#title' => $this->t('Usage Examples'),
      '#open' => FALSE,
    ];

    $form['help']['examples'] = [
      '#markup' => $this->t('
        <strong>Examples:</strong><br>
        • Convert to uppercase: <code>return strtoupper($value);</code><br>
        • Trim whitespace: <code>return trim($value);</code><br>
        • Use other field data: <code>return $item_data["title"] . " - " . $value;</code><br>
        • Conditional logic: <code>return empty($value) ? "Default" : $value;</code>
      '),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitConfigurationForm($form, $form_state);
    $this->setConfiguration([
      'php_code' => $form_state->getValue('php_code'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    $php_code = $form_state->getValue('php_code');

    if (empty($php_code)) {
      $form_state->setErrorByName('php_code', $this->t('PHP code is required.'));
      return;
    }

    // Basic syntax check - disallow PHP tags
    if (strpos($php_code, '<?php') !== FALSE || strpos($php_code, '?>') !== FALSE) {
      $form_state->setErrorByName('php_code', $this->t('Do not include PHP opening/closing tags.'));
    }

    // Simple syntax validation
    $test_code = "return function(\$value, \$item_data) { $php_code };";
    $error = NULL;

    set_error_handler(function ($severity, $message) use (&$error) {
      $error = $message;
    });

    $result = @eval($test_code);

    restore_error_handler();

    if ($result === FALSE || $error) {
      $form_state->setErrorByName('php_code', $this->t('PHP syntax error: @error', ['@error' => $error ?: 'Invalid syntax']));
    }
  }
}
