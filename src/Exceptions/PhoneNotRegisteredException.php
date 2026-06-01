<?php

namespace JordanMiguel\Wuz\Exceptions;

/**
 * Thrown when a phone number is not reachable on WhatsApp. Distinct from a
 * transient API failure so callers can treat it as permanently undeliverable
 * (no retry) rather than retrying the send.
 */
class PhoneNotRegisteredException extends WuzApiException {}
