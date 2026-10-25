<?php
namespace MammalWeb\Plugin\User\ProfileMW\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Form\Field\ListField;

/**
 * Custom Joomla field type: SelectOrText
 * Allows selecting from predefined XML options OR entering custom text.
 */
class SelectOrTextField extends ListField
{
    protected $type = 'SelectOrText';

    /**
     * Get the HTML for the field input.
     */
    protected function getInput()
    {
        // Get options from XML definition
        $options = $this->getOptions();

        // Build select HTML
        $html = '<select id="' . $this->id . '_select" class="form-select">';
        foreach ($options as $option) {
            $selected = ($this->value === (string) $option->value) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($option->value) . '"' . $selected . '>'
                . htmlspecialchars($option->text) . '</option>';
        }
        $html .= '</select>';

        // Build text input HTML
        $html .= '<input type="text" name="' . $this->name . '" id="' . $this->id . '"'
            . ' value="' . htmlspecialchars($this->value) . '" class="form-control mt-2" />';

        // Add JS to sync select → input
        $html .= "<script>
            document.getElementById('{$this->id}_select').addEventListener('change', function() {
                document.getElementById('{$this->id}').value = this.value;
            });
        </script>";

        return $html;
    }
}