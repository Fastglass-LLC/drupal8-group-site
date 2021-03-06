<?php

namespace Drupal\Tests\clientside_validation\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\JavascriptTestBase;

/**
 * Test Clientside Validations.
 *
 * @group clientside_validation
 */
class ClientsideValidationTest extends JavascriptTestBase {

  const DEMO_FORM_URL = '/admin/config/user-interface/clientside-validation-demo';

  /**
   * A user with permission to access demo form.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * @var \Behat\Mink\Element\DocumentElement
   */
  protected $page;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'clientside_validation_demo',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser([
      'access administration pages',
      'administer site configuration',
    ]);
  }

  /**
   * Validate error messages.
   */
  public function testValidations() {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet(self::DEMO_FORM_URL);

    $this->page = $this->getSession()->getPage();

    // Required.
    $assertions = [];

    // Default required field error from text_1.
    $assertions['edit-text-1'] = 'Text 1 is required.';

    // Custom required field error from text_2.
    $assertions['edit-text-2'] = 'This message is coming from #required_error.';

    // Default required field error from email_1.
    $assertions['edit-email-1'] = 'E-Mail 1 is required.';

    $this->_validateAssertions($assertions);

    // URL field.
    $assertions = [];

    // Invalid URL validation for url.
    $this->page->findById('edit-url')->setValue('invalid url');
    $assertions['edit-url'] = 'URL does not contain a valid url.';

    $this->_validateAssertions($assertions);

    // E-Mail field.
    $assertions = [];

    // Invalid e-mail message from email_1.
    $this->page->findById('edit-email-1')->setValue('hello@world@w3c.org');
    $assertions['edit-email-1'] = 'E-Mail 1 does not contain a valid email.';

    // Invalid e-mail message from email_2.
    $this->page->findById('edit-email-2')->setValue('asdf');
    $assertions['edit-email-2'] = 'E-Mail 2 does not contain a valid email.';

    $this->_validateAssertions($assertions);

    // Numeric field.
    $assertions = [];

    // Set greater than max in number field 2.
    $this->page->findById('edit-number-2')->setValue('150');
    $assertions['edit-number-2'] = 'The value in Number 2 has to be less than 100.';

    // Set less than min in number field 3.
    $this->page->findById('edit-number-3')->setValue('50');
    $assertions['edit-number-3'] = 'The value in Number 3 has to be greater than 100.';

    // Set number breaking step rule in number field 5.
    $this->page->findById('edit-number-5')->setValue('101');
    $assertions['edit-number-5'] = 'The value in Number 5 has to be greater than 100 by steps of 5.';

    $this->_validateAssertions($assertions);

    // Set all valid values.
    $this->page->findById('edit-text-1')->setValue('text 1');
    $this->page->findById('edit-text-2')->setValue('text 2');
    $this->page->findById('edit-text-3')->setValue('text 3');
    $this->page->findById('edit-email-1')->setValue('admin@localhost.com');
    $this->page->findById('edit-email-2')->setValue('dev@example.com');
    $this->page->findById('edit-number-1')->setValue('100');
    $this->page->findById('edit-number-2')->setValue('100');
    $this->page->findById('edit-number-3')->setValue('100');
    $this->page->findById('edit-number-4')->setValue('100');
    $this->page->findById('edit-number-5')->setValue('100');
    $this->page->findById('edit-url')->setValue('http://example.com');

    // Trigger click of submit button.
    $this->page->findButton('Submit')->click();

    $wrapper = $this->page->find('css', '.messages.messages--status');
    $this->assertContains('All form validations passed.', $wrapper->getText());
  }

  /**
   * Helper function to validate assertions.
   *
   * @param array $assertions
   *   Assertions to validate.
   *
   * @throws \Exception
   *   Exception if element not found.
   */
  private function _validateAssertions(array $assertions) {
    // Trigger click of submit button.
    $this->page->findButton('Submit')->click();

    foreach ($assertions as $key => $expected) {
      $errorElement = $this->page->findById($key . '-error');

      if (empty($errorElement)) {
        // We will fail this scenario.
        $this->assertEquals($expected, '');

        continue;
      }

      $actual = $errorElement->getText();
      $this->assertEquals($expected, $actual);
    }
  }

}
