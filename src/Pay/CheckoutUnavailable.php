<?php

namespace App\Pay;

/** Cyllos could not be reached, answered unexpectedly, or Stripe is down. */
final class CheckoutUnavailable extends \RuntimeException
{
}
