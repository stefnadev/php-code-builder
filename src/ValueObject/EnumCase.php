<?php declare(strict_types=1);

namespace Stefna\PhpCodeBuilder\ValueObject;

class EnumCase
{
	public function __construct(
		protected string $name,
	) {}

	public function getName(): string
	{
		$currentName = $this->name;
		$sanitizedName = str_replace('-', '_', $currentName);
		$sanitizedName = str_replace(' ', '', ucwords(str_replace('_', ' ', $sanitizedName)));
		$sanitizedName = (string)preg_replace('/[^A-Za-z0-9_]/', '_s_', $sanitizedName);
		$sanitizedName = (string)preg_replace('/^(\d)/', '_$0', $sanitizedName);

		return $sanitizedName;
	}
}
