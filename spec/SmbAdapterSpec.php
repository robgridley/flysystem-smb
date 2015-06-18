<?php

namespace spec\RobGridley\Flysystem\Smb;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class SmbAdapterSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->shouldHaveType('RobGridley\Flysystem\Smb\SmbAdapter');
    }
}
