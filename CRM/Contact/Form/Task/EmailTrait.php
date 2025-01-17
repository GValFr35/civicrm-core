<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

use Civi\Api4\Email;

/**
 * This class provides the common functionality for tasks that send emails.
 */
trait CRM_Contact_Form_Task_EmailTrait {

  /**
   * Are we operating in "single mode", i.e. sending email to one
   * specific contact?
   *
   * @var bool
   */
  public $_single = FALSE;

  /**
   * All the existing templates in the system.
   *
   * @var array
   */
  public $_templates;

  /**
   * Store "to" contact details.
   * @var array
   */
  public $_toContactDetails = [];

  /**
   * Store all selected contact id's, that includes to, cc and bcc contacts
   * @var array
   */
  public $_allContactIds = [];

  /**
   * Store only "to" contact ids.
   * @var array
   */
  public $_toContactIds = [];

  /**
   * Is the form being loaded from a search action.
   *
   * @var bool
   */
  public $isSearchContext = TRUE;

  public $contactEmails = [];

  /**
   * Contacts form whom emails could not be sent.
   *
   * An array of contact ids and the relevant message details.
   *
   * @var array
   */
  protected $suppressedEmails = [];

  /**
   * Getter for isSearchContext.
   *
   * @return bool
   */
  public function isSearchContext(): bool {
    return $this->isSearchContext;
  }

  /**
   * Setter for isSearchContext.
   *
   * @param bool $isSearchContext
   */
  public function setIsSearchContext(bool $isSearchContext) {
    $this->isSearchContext = $isSearchContext;
  }

  /**
   * Build all the data structures needed to build the form.
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \CRM_Core_Exception
   */
  public function preProcess() {
    $this->traitPreProcess();
  }

  /**
   * Call trait preProcess function.
   *
   * This function exists as a transitional arrangement so classes overriding
   * preProcess can still call it. Ideally it will be melded into preProcess
   * later.
   *
   * @throws \CRM_Core_Exception
   * @throws \API_Exception
   */
  protected function traitPreProcess() {
    $this->preProcessFromAddress();
    if ($this->isSearchContext()) {
      // Currently only the contact email form is callable outside search context.
      parent::preProcess();
    }
    $this->setContactIDs();
    $this->assign('single', $this->_single);
    if (CRM_Core_Permission::check('administer CiviCRM')) {
      $this->assign('isAdmin', 1);
    }
  }

  /**
   * Pre Process Form Addresses to be used in Quickform
   *
   * @throws \API_Exception
   * @throws \CRM_Core_Exception
   */
  protected function preProcessFromAddress(): void {
    $form = $this;
    $form->_emails = [];

    // @TODO remove these line and to it somewhere more appropriate. Currently some classes (e.g Case
    // are having to re-write contactIds afterwards due to this inappropriate variable setting
    // If we don't have any contact IDs, use the logged in contact ID
    $form->_contactIds = $form->_contactIds ?: [CRM_Core_Session::getLoggedInContactID()];

    $fromEmailValues = CRM_Core_BAO_Email::getFromEmail();

    if (empty($fromEmailValues)) {
      CRM_Core_Error::statusBounce(ts('Your user record does not have a valid email address and no from addresses have been configured.'));
    }

    $form->_emails = $fromEmailValues;
    $defaults = [];
    $form->_fromEmails = $fromEmailValues;
    if (is_numeric(key($form->_fromEmails))) {
      $emailID = (int) key($form->_fromEmails);
      $defaults = CRM_Core_BAO_Email::getEmailSignatureDefaults($emailID);
    }
    if (!Civi::settings()->get('allow_mail_from_logged_in_contact')) {
      $defaults['from_email_address'] = current(CRM_Core_BAO_Domain::getNameAndEmail(FALSE, TRUE));
    }
    $form->setDefaults($defaults);
  }

  /**
   * Build the form object.
   *
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm() {
    // Suppress form might not be required but perhaps there was a risk some other  process had set it to TRUE.
    $this->assign('suppressForm', FALSE);
    $this->assign('emailTask', TRUE);

    $toArray = [];
    $suppressedEmails = 0;
    //here we are getting logged in user id as array but we need target contact id. CRM-5988
    $cid = $this->get('cid');
    if ($cid) {
      $this->_contactIds = explode(',', $cid);
    }
    if (count($this->_contactIds) > 1) {
      $this->_single = FALSE;
    }
    $this->bounceIfSimpleMailLimitExceeded(count($this->_contactIds));

    $emailAttributes = [
      'class' => 'huge',
    ];
    $to = $this->add('text', 'to', ts('To'), $emailAttributes, TRUE);

    $this->addEntityRef('cc_id', ts('CC'), [
      'entity' => 'Email',
      'multiple' => TRUE,
    ]);

    $this->addEntityRef('bcc_id', ts('BCC'), [
      'entity' => 'Email',
      'multiple' => TRUE,
    ]);

    if ($to->getValue()) {
      $this->_toContactIds = $this->_contactIds = [];
    }
    $setDefaults = TRUE;
    if (property_exists($this, '_context') && $this->_context === 'standalone') {
      $setDefaults = FALSE;
    }

    $this->_allContactIds = $this->_toContactIds = $this->_contactIds;

    if ($to->getValue()) {
      foreach ($this->getEmails($to) as $value) {
        $contactId = $value['contact_id'];
        $email = $value['email'];
        if ($contactId) {
          $this->_contactIds[] = $this->_toContactIds[] = $contactId;
          $this->_toContactEmails[] = $email;
          $this->_allContactIds[] = $contactId;
        }
      }
      $setDefaults = TRUE;
    }

    //get the group of contacts as per selected by user in case of Find Activities
    if (!empty($this->_activityHolderIds)) {
      $contact = $this->get('contacts');
      $this->_allContactIds = $this->_contactIds = $contact;
    }

    // check if we need to setdefaults and check for valid contact emails / communication preferences
    if (is_array($this->_allContactIds) && $setDefaults) {
      // get the details for all selected contacts ( to, cc and bcc contacts )
      $allContactDetails = civicrm_api3('Contact', 'get', [
        'id' => ['IN' => $this->_allContactIds],
        'return' => ['sort_name', 'email', 'do_not_email', 'is_deceased', 'on_hold', 'display_name', 'preferred_mail_format'],
        'options' => ['limit' => 0],
      ])['values'];

      // The contact task supports passing in email_id in a url. It supports a single email
      // and is marked as having been related to CiviHR.
      // The array will look like $this->_toEmail = ['email' => 'x', 'contact_id' => 2])
      // If it exists we want to use the specified email which might be different to the primary email
      // that we have.
      if (!empty($this->_toEmail['contact_id']) && !empty($allContactDetails[$this->_toEmail['contact_id']])) {
        $allContactDetails[$this->_toEmail['contact_id']]['email'] = $this->_toEmail['email'];
      }

      // perform all validations on unique contact Ids
      foreach ($allContactDetails as $contactId => $value) {
        if ($value['do_not_email'] || empty($value['email']) || !empty($value['is_deceased']) || $value['on_hold']) {
          $this->setSuppressedEmail($contactId, $value);
        }
        elseif (in_array($contactId, $this->_toContactIds)) {
          $this->_toContactDetails[$contactId] = $this->_contactDetails[$contactId] = $value;
          $toArray[] = [
            'text' => '"' . $value['sort_name'] . '" <' . $value['email'] . '>',
            'id' => "$contactId::{$value['email']}",
          ];
        }
      }

      if (empty($toArray)) {
        CRM_Core_Error::statusBounce(ts('Selected contact(s) do not have a valid email address, or communication preferences specify DO NOT EMAIL, or they are deceased or Primary email address is On Hold.'));
      }
    }

    $this->assign('toContact', json_encode($toArray));

    $this->assign('suppressedEmails', count($this->suppressedEmails));

    $this->assign('totalSelectedContacts', count($this->_contactIds));

    $this->add('text', 'subject', ts('Subject'), ['size' => 50, 'maxlength' => 254], TRUE);

    $this->add('select', 'from_email_address', ts('From'), $this->_fromEmails, TRUE);

    CRM_Mailing_BAO_Mailing::commonCompose($this);

    // add attachments
    CRM_Core_BAO_File::buildAttachment($this, NULL);

    if ($this->_single) {
      // also fix the user context stack
      if ($this->getCaseID()) {
        $ccid = CRM_Core_DAO::getFieldValue('CRM_Case_DAO_CaseContact', $this->_caseId,
          'contact_id', 'case_id'
        );
        $url = CRM_Utils_System::url('civicrm/contact/view/case',
          "&reset=1&action=view&cid={$ccid}&id=" . $this->getCaseID()
        );
      }
      elseif ($this->_context) {
        $url = CRM_Utils_System::url('civicrm/dashboard', 'reset=1');
      }
      else {
        $url = CRM_Utils_System::url('civicrm/contact/view',
          "&show=1&action=browse&cid={$this->_contactIds[0]}&selectedChild=activity"
        );
      }

      $session = CRM_Core_Session::singleton();
      $session->replaceUserContext($url);
      $this->addDefaultButtons(ts('Send Email'), 'upload', 'cancel');
    }
    else {
      $this->addDefaultButtons(ts('Send Email'), 'upload');
    }

    $fields = [
      'followup_assignee_contact_id' => [
        'type' => 'entityRef',
        'label' => ts('Assigned to'),
        'attributes' => [
          'multiple' => TRUE,
          'create' => TRUE,
          'api' => ['params' => ['is_deceased' => 0]],
        ],
      ],
      'followup_activity_type_id' => [
        'type' => 'select',
        'label' => ts('Followup Activity'),
        'attributes' => ['' => '- ' . ts('select activity') . ' -'] + CRM_Core_PseudoConstant::ActivityType(FALSE),
        'extra' => ['class' => 'crm-select2'],
      ],
      'followup_activity_subject' => [
        'type' => 'text',
        'label' => ts('Subject'),
        'attributes' => CRM_Core_DAO::getAttribute('CRM_Activity_DAO_Activity',
          'subject'
        ),
      ],
    ];

    //add followup date
    $this->add('datepicker', 'followup_date', ts('in'));

    foreach ($fields as $field => $values) {
      if (!empty($fields[$field])) {
        $attribute = $values['attributes'] ?? NULL;
        $required = !empty($values['required']);

        if ($values['type'] === 'select' && empty($attribute)) {
          $this->addSelect($field, ['entity' => 'activity'], $required);
        }
        elseif ($values['type'] === 'entityRef') {
          $this->addEntityRef($field, $values['label'], $attribute, $required);
        }
        else {
          $this->add($values['type'], $field, $values['label'], $attribute, $required, CRM_Utils_Array::value('extra', $values));
        }
      }
    }

    //Added for CRM-15984: Add campaign field
    CRM_Campaign_BAO_Campaign::addCampaign($this);

    $this->addFormRule([__CLASS__, 'saveTemplateFormRule'], $this);
    $this->addFormRule([__CLASS__, 'deprecatedTokensFormRule'], $this);
    CRM_Core_Resources::singleton()->addScriptFile('civicrm', 'templates/CRM/Contact/Form/Task/EmailCommon.js', 0, 'html-header');
  }

  /**
   * Process the form after the input has been submitted and validated.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \API_Exception
   */
  public function postProcess() {
    $this->bounceIfSimpleMailLimitExceeded(count($this->_contactIds));

    // check and ensure that
    $formValues = $this->controller->exportValues($this->getName());
    $this->submit($formValues);
  }

  /**
   * Bounce if there are more emails than permitted.
   *
   * @param int $count
   *  The number of emails the user is attempting to send
   */
  protected function bounceIfSimpleMailLimitExceeded($count) {
    $limit = Civi::settings()->get('simple_mail_limit');
    if ($count > $limit) {
      CRM_Core_Error::statusBounce(ts('Please do not use this task to send a lot of emails (greater than %1). Many countries have legal requirements when sending bulk emails and the CiviMail framework has opt out functionality and domain tokens to help meet these.',
        [1 => $limit]
      ));
    }
  }

  /**
   * Submit the form values.
   *
   * This is also accessible for testing.
   *
   * @param array $formValues
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \API_Exception
   */
  public function submit($formValues) {
    $this->saveMessageTemplate($formValues);

    $from = $formValues['from_email_address'] ?? NULL;
    // dev/core#357 User Emails are keyed by their id so that the Signature is able to be added
    // If we have had a contact email used here the value returned from the line above will be the
    // numerical key where as $from for use in the sendEmail in Activity needs to be of format of "To Name" <toemailaddress>
    $from = CRM_Utils_Mail::formatFromAddress($from);

    $ccArray = $formValues['cc_id'] ? explode(',', $formValues['cc_id']) : [];
    $cc = $this->getEmailString($ccArray);
    $additionalDetails = empty($ccArray) ? '' : "\ncc : " . $this->getEmailUrlString($ccArray);

    $bccArray = $formValues['bcc_id'] ? explode(',', $formValues['bcc_id']) : [];
    $bcc = $this->getEmailString($bccArray);
    $additionalDetails .= empty($bccArray) ? '' : "\nbcc : " . $this->getEmailUrlString($bccArray);

    // format contact details array to handle multiple emails from same contact
    $formattedContactDetails = [];
    foreach ($this->_contactIds as $key => $contactId) {
      // if we dont have details on this contactID, we should ignore
      // potentially this is due to the contact not wanting to receive email
      if (!isset($this->_contactDetails[$contactId])) {
        continue;
      }
      $email = $this->_toContactEmails[$key];
      // prevent duplicate emails if same email address is selected CRM-4067
      // we should allow same emails for different contacts
      $details = $this->_contactDetails[$contactId];
      $details['email'] = $email;
      unset($details['email_id']);
      $formattedContactDetails["{$contactId}::{$email}"] = $details;
    }

    // send the mail
    [$sent, $activityIds] = CRM_Activity_BAO_Activity::sendEmail(
      $formattedContactDetails,
      $this->getSubject($formValues['subject']),
      $formValues['text_message'],
      $formValues['html_message'],
      NULL,
      NULL,
      $from,
      $this->getAttachments($formValues),
      $cc,
      $bcc,
      array_keys($this->_toContactDetails),
      $additionalDetails,
      $this->getContributionIDs(),
      CRM_Utils_Array::value('campaign_id', $formValues),
      $this->getCaseID()
    );

    if ($sent) {
      // Only use the first activity id if there's multiple.
      // If there's multiple recipients the idea behind multiple activities
      // is to record the token value replacements separately, but that
      // has no meaning for followup activities, and this doesn't prevent
      // creating more manually if desired.
      $followupStatus = $this->createFollowUpActivities($formValues, $activityIds[0]);
      $count_success = count($this->_toContactDetails);
      CRM_Core_Session::setStatus(ts('One message was sent successfully. ', [
        'plural' => '%count messages were sent successfully. ',
        'count' => $count_success,
      ]) . $followupStatus, ts('Message Sent', ['plural' => 'Messages Sent', 'count' => $count_success]), 'success');
    }

    if (!empty($this->suppressedEmails)) {
      $status = '(' . ts('because no email address on file or communication preferences specify DO NOT EMAIL or Contact is deceased or Primary email address is On Hold') . ')<ul><li>' . implode('</li><li>', $this->suppressedEmails) . '</li></ul>';
      CRM_Core_Session::setStatus($status, ts('One Message Not Sent', [
        'count' => count($this->suppressedEmails),
        'plural' => '%count Messages Not Sent',
      ]), 'info');
    }
  }

  /**
   * Save the template if update selected.
   *
   * @param array $formValues
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function saveMessageTemplate($formValues) {
    if (!empty($formValues['saveTemplate']) || !empty($formValues['updateTemplate'])) {
      $messageTemplate = [
        'msg_text' => $formValues['text_message'],
        'msg_html' => $formValues['html_message'],
        'msg_subject' => $formValues['subject'],
        'is_active' => TRUE,
      ];

      if (!empty($formValues['saveTemplate'])) {
        $messageTemplate['msg_title'] = $formValues['saveTemplateName'];
        CRM_Core_BAO_MessageTemplate::add($messageTemplate);
      }

      if (!empty($formValues['template']) && !empty($formValues['updateTemplate'])) {
        $messageTemplate['id'] = $formValues['template'];
        unset($messageTemplate['msg_title']);
        CRM_Core_BAO_MessageTemplate::add($messageTemplate);
      }
    }
  }

  /**
   * List available tokens for this form.
   *
   * @return array
   */
  public function listTokens() {
    return CRM_Core_SelectValues::contactTokens();
  }

  /**
   * Get the emails from the added element.
   *
   * @param HTML_QuickForm_Element $element
   *
   * @return array
   */
  protected function getEmails($element): array {
    $allEmails = explode(',', $element->getValue());
    $return = [];
    foreach ($allEmails as $value) {
      $values = explode('::', $value);
      $return[] = ['contact_id' => $values[0], 'email' => $values[1]];
    }
    return $return;
  }

  /**
   * Get the string for the email IDs.
   *
   * @param array $emailIDs
   *   Array of email IDs.
   *
   * @return string
   *   e.g. "Smith, Bob<bob.smith@example.com>".
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getEmailString(array $emailIDs): string {
    if (empty($emailIDs)) {
      return '';
    }
    $emails = Email::get()
      ->addWhere('id', 'IN', $emailIDs)
      ->setCheckPermissions(FALSE)
      ->setSelect(['contact_id', 'email', 'contact_id.sort_name', 'contact_id.display_name'])->execute();
    $emailStrings = [];
    foreach ($emails as $email) {
      $this->contactEmails[$email['id']] = $email;
      $emailStrings[] = '"' . $email['contact_id.sort_name'] . '" <' . $email['email'] . '>';
    }
    return implode(',', $emailStrings);
  }

  /**
   * Get the url string.
   *
   * This is called after the contacts have been retrieved so we don't need to re-retrieve.
   *
   * @param array $emailIDs
   *
   * @return string
   *   e.g. <a href='{$contactURL}'>Bob Smith</a>'
   */
  protected function getEmailUrlString(array $emailIDs): string {
    $urls = [];
    foreach ($emailIDs as $email) {
      $contactURL = CRM_Utils_System::url('civicrm/contact/view', ['reset' => 1, 'cid' => $this->contactEmails[$email]['contact_id']], TRUE);
      $urls[] = "<a href='{$contactURL}'>" . $this->contactEmails[$email]['contact_id.display_name'] . '</a>';
    }
    return implode(', ', $urls);
  }

  /**
   * Set the emails that are not to be sent out.
   *
   * @param int $contactID
   * @param array $values
   */
  protected function setSuppressedEmail($contactID, $values) {
    $contactViewUrl = CRM_Utils_System::url('civicrm/contact/view', 'reset=1&cid=' . $contactID);
    $this->suppressedEmails[$contactID] = "<a href='$contactViewUrl' title='{$values['email']}'>{$values['display_name']}</a>" . ($values['on_hold'] ? '(' . ts('on hold') . ')' : '');
  }

  /**
   * Get any attachments.
   *
   * @param array $formValues
   *
   * @return array
   */
  protected function getAttachments(array $formValues): array {
    $attachments = [];
    CRM_Core_BAO_File::formatAttachment($formValues,
      $attachments,
      NULL, NULL
    );
    return $attachments;
  }

  /**
   * Get the subject for the message.
   *
   * The case handling should possibly be on the case form.....
   *
   * @param string $subject
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getSubject(string $subject):string {
    // CRM-5916: prepend case id hash to CiviCase-originating emails’ subjects
    if ($this->getCaseID()) {
      $hash = substr(sha1(CIVICRM_SITE_KEY . $this->getCaseID()), 0, 7);
      $subject = "[case #$hash] $subject";
    }
    return $subject;
  }

  /**
   * Create any follow up activities.
   *
   * @param array $formValues
   * @param int $activityId
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  protected function createFollowUpActivities($formValues, $activityId): string {
    $params = [];
    $followupStatus = '';
    $followupActivity = NULL;
    if (!empty($formValues['followup_activity_type_id'])) {
      $params['followup_activity_type_id'] = $formValues['followup_activity_type_id'];
      $params['followup_activity_subject'] = $formValues['followup_activity_subject'];
      $params['followup_date'] = $formValues['followup_date'];
      $params['target_contact_id'] = $this->_contactIds;
      $params['followup_assignee_contact_id'] = array_filter(explode(',', $formValues['followup_assignee_contact_id']));
      $followupActivity = CRM_Activity_BAO_Activity::createFollowupActivity($activityId, $params);
      $followupStatus = ts('A followup activity has been scheduled.');

      if (Civi::settings()->get('activity_assignee_notification')) {
        if ($followupActivity) {
          $mailToFollowupContacts = [];
          $assignee = [$followupActivity->id];
          $assigneeContacts = CRM_Activity_BAO_ActivityAssignment::getAssigneeNames($assignee, TRUE, FALSE);
          foreach ($assigneeContacts as $values) {
            $mailToFollowupContacts[$values['email']] = $values;
          }

          $sentFollowup = CRM_Activity_BAO_Activity::sendToAssignee($followupActivity, $mailToFollowupContacts);
          if ($sentFollowup) {
            $followupStatus .= '<br />' . ts('A copy of the follow-up activity has also been sent to follow-up assignee contacts(s).');
          }
        }
      }
    }
    return $followupStatus;
  }

  /**
   * Form rule.
   *
   * @param array $fields
   *   The input form values.
   *
   * @return bool|array
   *   true if no errors, else array of errors
   */
  public static function saveTemplateFormRule(array $fields) {
    $errors = [];
    //Added for CRM-1393
    if (!empty($fields['saveTemplate']) && empty($fields['saveTemplateName'])) {
      $errors['saveTemplateName'] = ts('Enter name to save message template');
    }
    return empty($errors) ? TRUE : $errors;
  }

  /**
   * Prevent submission of deprecated tokens.
   *
   * @param array $fields
   *
   * @return bool|string[]
   */
  public static function deprecatedTokensFormRule(array $fields) {
    $deprecatedTokens = [
      '{case.status_id}' => '{case.status_id:label}',
      '{case.case_type_id}' => '{case.case_type_id:label}',
    ];
    $tokenErrors = [];
    foreach ($deprecatedTokens as $token => $replacement) {
      if (strpos($fields['html_message'], $token) !== FALSE) {
        $tokenErrors[] = ts('Token %1 is no longer supported - use %2 instead', [$token, $replacement]);
      }
    }
    return empty($tokenErrors) ? TRUE : ['html_message' => implode('<br>', $tokenErrors)];
  }

  /**
   * Get selected contribution IDs.
   *
   * @return array
   */
  protected function getContributionIDs(): array {
    return [];
  }

  /**
   * Get case ID - if any.
   *
   * @return int|null
   *
   * @throws \CRM_Core_Exception
   */
  protected function getCaseID(): ?int {
    $caseID = CRM_Utils_Request::retrieve('caseid', 'String', $this);
    if ($caseID) {
      return (int) $caseID;
    }
    return NULL;
  }

}
