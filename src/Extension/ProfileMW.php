<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  User.profileMW
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace MammalWeb\Plugin\User\ProfileMW\Extension;

require_once JPATH_SITE . '/components/com_biodiv/src/Library/local.php';

use Joomla\CMS\Date\Date;
use Joomla\CMS\Event\Model\PrepareDataEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\User\AfterDeleteEvent;
use Joomla\CMS\Event\User\AfterSaveEvent;
use Joomla\CMS\Event\User\BeforeSaveEvent;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\ArrayHelper;

use MammalWeb\Component\Biodiv\Site\Library\Wrapper\Octopus;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Custom profile plugin for MammalWeb.
 *
 * @since  1.6
 */
final class ProfileMW extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * Date of birth.
     *
     * @var    string
     *
     * @since  3.1
     */
    private $date = '';

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return array
     *
     * @since   5.3.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareData' => 'onContentPrepareData',
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onUserBeforeSave'     => 'onUserBeforeSave',
            'onUserAfterSave'      => 'onUserAfterSave',
            'onUserAfterDelete'    => 'onUserAfterDelete',
        ];
    }

    /**
     * Runs on content preparation
     *
     * @param   PrepareDataEvent $event  The event instance.
     *
     * @return  void
     *
     * @since   1.6
     */
    public function onContentPrepareData(PrepareDataEvent $event)
    {
        $context = $event->getContext();
        $data    = $event->getData();

        // Check we are manipulating a valid form.
        if (!\in_array($context, ['com_users.profile', 'com_users.user', 'com_users.registration'])) {
            return;
        }

        // Load plugin language files
        $this->loadLanguage();

        if (\is_object($data)) {
            $userId = $data->id ?? 0;

            if (!isset($data->profile) && $userId > 0) {
                // Load the profile data from the database.
                $db    = $this->getDatabase();
                $query = $db->getQuery(true)
                    ->select(
                        [
                            $db->quoteName('profile_key'),
                            $db->quoteName('profile_value'),
                        ]
                    )
                    ->from($db->quoteName('#__user_profiles'))
                    ->where($db->quoteName('user_id') . ' = :userid')
                    ->where($db->quoteName('profile_key') . ' LIKE ' . $db->quote('profileMW.%'))
                    ->order($db->quoteName('ordering'))
                    ->bind(':userid', $userId, ParameterType::INTEGER);

                $db->setQuery($query);
                $results = $db->loadRowList();

                // Merge the profile data.
                $data->profile = [];

                foreach ($results as $v) {
                    $k                 = str_replace('profileMW.', '', $v[0]);
                    $data->profile[$k] = json_decode($v[1], true);

                    if ($data->profile[$k] === null) {
                        $data->profile[$k] = $v[1];
                    }
                }
            }
        }
    }

 

    /**
     * Adds additional fields to the user editing form
     *
     * @param   PrepareFormEvent $event  The event instance.
     *
     * @return  void
     *
     * @since   1.6
     */
    public function onContentPrepareForm(PrepareFormEvent $event)
    {
        $form = $event->getForm();
        // Check we are manipulating a valid form.
        $name = $form->getName();

        if (!\in_array($name, ['com_users.user', 'com_users.profile', 'com_users.registration'])) {
            return;
        }

        // Load plugin language files
        $this->loadLanguage();

        // Add the registration fields to the form.
        FormHelper::addFieldPrefix('MammalWeb\\Plugin\\User\\ProfileMW\\Field');
        FormHelper::addFormPath(JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name . '/forms');
        $form->loadFile('profilemw');

        $fields = [
            'subscribe',
            'wherehear',
            'gardenbw',
            'tos',
        ];

        //$tosArticle = $this->params->get('register_tos_article');
        $tosEnabled = $this->params->get('register-require_tos', 0);

        // We need to be in the registration form and field needs to be enabled
        if ($name !== 'com_users.registration' || !$tosEnabled) {
            // We only want the TOS in the registration form
            $form->removeField('tos', 'profilemw');
        } 

        foreach ($fields as $field) {
            // Case using the users manager in admin
            if ($name === 'com_users.user') {
                // Toggle whether the field is required.
                if ($this->params->get('profile-require_' . $field, 1) > 0) {
                    $form->setFieldAttribute($field, 'required', ($this->params->get('profile-require_' . $field) == 2) ? 'required' : '', 'profilemw');
                } elseif (
                    // Remove the field if it is disabled in registration and profile
                    $this->params->get('register-require_' . $field, 1) == 0
                    && $this->params->get('profile-require_' . $field, 1) == 0
                ) {
                    $form->removeField($field, 'profilemw');
                }
            } elseif ($name === 'com_users.registration') {
                // Case registration
                // Toggle whether the field is required.
                if ($this->params->get('register-require_' . $field, 1) > 0) {
                    $form->setFieldAttribute($field, 'required', ($this->params->get('register-require_' . $field) == 2) ? 'required' : '', 'profilemw');
                } else {
                    $form->removeField($field, 'profilemw');
                }
            } elseif ($name === 'com_users.profile') {
                // Case profile in site or admin
                // Toggle whether the field is required.
                if ($this->params->get('profile-require_' . $field, 1) > 0) {
                    $form->setFieldAttribute($field, 'required', ($this->params->get('profile-require_' . $field) == 2) ? 'required' : '', 'profilemw');
                } else {
                    $form->removeField($field, 'profilemw');
                }
            }
        }

        // Drop the profile form entirely if there aren't any fields to display.
        $remainingfields = $form->getGroup('profilemw');

        if (!\count($remainingfields)) {
            $form->removeGroup('profilemw');
        }
    }

    /**
     * Method is called before user data is stored in the database
     *
     * @param   BeforeSaveEvent $event  The event instance.
     *
     * @return  void
     *
     * @since   3.1
     * @throws  \InvalidArgumentException on invalid date.
     */
    public function onUserBeforeSave(BeforeSaveEvent $event)
    {
        $data = $event->getData();

        // Load plugin language files
        $this->loadLanguage();

        // Check that the tos is checked if required ie only in registration from frontend.
        $task       = $this->getApplication()->getInput()->getCmd('task');
        $option     = $this->getApplication()->getInput()->getCmd('option');
        $tosEnabled = ($this->params->get('register-require_tos', 0) == 2);

        // Check that the tos is checked.
        if ($task === 'register' && $tosEnabled && $option === 'com_users' && !$data['profilemw']['tos']) {
            throw new \InvalidArgumentException($this->getApplication()->getLanguage()->_('PLG_USER_PROFILEMW_FIELD_TOS_DESC_SITE'));
        }
    }

    /**
     * Saves user profile data
     *
     * @param   AfterSaveEvent $event  The event instance.
     *
     * @return  void
     */
    public function onUserAfterSave(AfterSaveEvent $event): void
    {
        $data   = $event->getUser();
        $result = $event->getSavingResult();
        $userId = ArrayHelper::getValue($data, 'id', 0, 'int');

        if ($userId && $result && isset($data['profilemw']) && \count($data['profilemw'])) {
            $db = $this->getDatabase();

            $keys = array_keys($data['profilemw']);

            foreach ($keys as &$key) {
                $key = 'profileMW.' . $key;
            }

            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__user_profiles'))
                ->where($db->quoteName('user_id') . ' = :userid')
                ->whereIn($db->quoteName('profile_key'), $keys, ParameterType::STRING)
                ->bind(':userid', $userId, ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();

            $query->clear()
                ->select($db->quoteName('ordering'))
                ->from($db->quoteName('#__user_profiles'))
                ->where($db->quoteName('user_id') . ' = :userid')
                ->bind(':userid', $userId, ParameterType::INTEGER);
            $db->setQuery($query);
            $usedOrdering = $db->loadColumn();

            $order = 1;
            $query->clear()
                ->insert($db->quoteName('#__user_profiles'));
				
			$toSubscribe = false;

            foreach ($data['profilemw'] as $k => $v) {
                while (\in_array($order, $usedOrdering)) {
                    $order++;
                }

                $query->values(
                    implode(
                        ',',
                        $query->bindArray(
                            [
                                $userId,
                                'profileMW.' . $k,
                                json_encode($v),
                                $order++,
                            ],
                            [
                                ParameterType::INTEGER,
                                ParameterType::STRING,
                                ParameterType::STRING,
                                ParameterType::INTEGER,
                            ]
                        )
                    )
                );
				if ( strcmp($k, 'subscribe') == 0 and $v == 1 ) {
					$toSubscribe = true;
				}
            }

            $db->setQuery($query);
            $db->execute();
			
			if ( $toSubscribe ) {

				$email  = ArrayHelper::getValue($data, 'email', 0, 'string');
				$name   = ArrayHelper::getValue($data, 'name', 0, 'string');

				$octopus = new Octopus();

				$subscribeSuccess = $octopus->subscribe ( $email, $name );

				if ( !$subscribeSuccess ) {

					error_log ("Failed to subscribe to newsletter for user " . $userId . ", email " . $email . ", name " . $name );
				}
			}

        }
    }

    /**
     * Remove all user profile information for the given user ID
     *
     * Method is called after user data is deleted from the database
     *
     * @param   AfterDeleteEvent $event  The event instance.
     *
     * @return  void
     */
    public function onUserAfterDelete(AfterDeleteEvent $event): void
    {
        $user    = $event->getUser();
        $success = $event->getDeletingResult();

        if (!$success) {
            return;
        }

        $userId = ArrayHelper::getValue($user, 'id', 0, 'int');

        if ($userId) {
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__user_profiles'))
                ->where($db->quoteName('user_id') . ' = :userid')
                ->where($db->quoteName('profile_key') . ' LIKE ' . $db->quote('profileMW.%'))
                ->bind(':userid', $userId, ParameterType::INTEGER);

            $db->setQuery($query);
            $db->execute();
        }
    }
}
