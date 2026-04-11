@managing_payment_methods
Feature: Adding a MONEI payment method
    In order to accept payments via MONEI
    As an Administrator
    I want to be able to add a MONEI payment method

    Background:
        Given the store operates on a single channel in "EUR" currency
        And I am logged in as an administrator

    @ui
    Scenario: Adding a MONEI payment method with redirect flow
        When I want to create a new payment method with "MONEI" gateway factory
        And I fill in MONEI API key with "pk_test_abc123"
        And I fill in MONEI account ID with "acc_test_456"
        And I choose "Redirect" integration type
        And I name it "MONEI Payments" in "English (United States)"
        And I add it
        Then I should be notified that it has been successfully created
        And the payment method "MONEI Payments" should appear in the registry

    @ui
    Scenario: Adding a MONEI payment method with embedded component flow
        When I want to create a new payment method with "MONEI" gateway factory
        And I fill in MONEI API key with "pk_test_abc123"
        And I fill in MONEI account ID with "acc_test_456"
        And I choose "Embedded Component" integration type
        And I name it "MONEI Component" in "English (United States)"
        And I add it
        Then I should be notified that it has been successfully created
