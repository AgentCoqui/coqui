<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

function toolFromToolkit(ToolkitInterface $toolkit, string $name): ToolInterface
{
	foreach ($toolkit->tools() as $tool) {
		if ($tool->name() === $name) {
			return $tool;
		}
	}

	throw new InvalidArgumentException(sprintf('Tool "%s" not found.', $name));
}
