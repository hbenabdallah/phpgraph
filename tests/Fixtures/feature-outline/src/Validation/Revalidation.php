<?php

namespace App\Validation;

/**
 * Runs the mutation validators again on an input already validated once.
 */
final class Revalidation
{
    public function __construct(private MutationValidators $validators)
    {
    }

    public function again(object $input): Notification
    {
        $notification = new Notification();
        $this->validators->validate($input, $notification);

        return $notification;
    }
}
