# Payments

The payments port and the system use cases that record what a payment provider reports.

`Api/` holds the port, `PaymentProvider` (`id()`, `accepts()`, `payUrl()`, `latestOf()`), contributed with `Contributions::paymentProvider()` and read through `PaymentProviders::active()` (the first one contributed), and its values: `PaymentRequest`, `RequestLine`, `OpenPayment`, `OpenPaymentState`, `RegistrationRef` (the `12:47K3` form). `CardPayments::start()` runs the use case `kalaka-portal/start-card-payment` (`StartCardPayment`, which checks what is payable now and builds the one-line request) and then asks the provider for the pay URL, outside the transaction. `OnlinePayments` is what a provider reports to: `recordPaid()` and `recordRefunded()` run the system use cases `kalaka/record-online-payment` and `kalaka/record-online-refund` (`RecordOnline`), which write one `payment` or `refund` ledger line per item, once, never gated. Refusals: `CardPaymentUnavailable` (`kalaka_card_payment_unavailable`) and `NoPaymentProvider` (`kalaka_no_payment_provider`).

Entry points: `Api\CardPayments`, `Api\OnlinePayments`.
