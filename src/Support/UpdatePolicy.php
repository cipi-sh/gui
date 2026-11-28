<?php

namespace CipiGui\Support;

/** How the server updates Cipi (`cipi self-update policy`): the choices of its form. */
final class UpdatePolicy
{
    /** Hours to wait before a new release is installed. */
    public const DELAYS = ['0' => 'No delay', '6' => '6 hours', '12' => '12 hours', '24' => '1 day', '48' => '2 days', '72' => '3 days', '168' => '1 week'];
}
