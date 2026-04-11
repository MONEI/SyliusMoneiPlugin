@paying_with_monei
Feature: Paying with MONEI via redirect
    In order to pay for my order
    As a Customer
    I want to be redirected to MONEI hosted payment page

    Background:
        Given the store operates on a single channel in "EUR" currency
        And the store has a product "T-Shirt" priced at "€15.00"
        And the store has a payment method "MONEI" with a code "monei"
        And this payment method uses MONEI gateway with redirect flow

    @ui
    Scenario: Successfully initiating a payment via redirect
        Given I added "T-Shirt" to the cart
        And I complete the checkout addressing step
        And I complete the checkout shipping step
        When I choose "MONEI" payment method
        And I confirm my order
        Then I should be redirected to the MONEI hosted payment page
