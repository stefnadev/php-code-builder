<?php declare(strict_types=1);

namespace Stefna\PhpCodeBuilder;

use Stefna\PhpCodeBuilder\ValueObject\Identifier;
use Stefna\PhpCodeBuilder\ValueObject\Type;

/**
 * Class that represents the source code for a variable in php
 *
 * @author Fredrik Wallgren <fredrik.wallgren@gmail.com>
 * @author Andreas Sundqvist <andreas@stefna.is>
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
class PhpVariable extends PhpElement
{
	private const NO_VALUE = '__PhpVariable_NoValue__';

	/** @var PhpDocComment|null */
	private $comment;
	/** @var string */
	private $initializedValue;
	/** @var Type */
	private $type;
	/** @var bool */
	private $static = false;
	/** @var bool */
	private $raw = false;

	public static function private(string $identifier, Type $type): self
	{
		return new self(self::PRIVATE_ACCESS, $identifier, self::NO_VALUE, $type);
	}

	public static function protected(string $identifier, Type $type): self
	{
		return new self(self::PROTECTED_ACCESS, $identifier, self::NO_VALUE, $type);
	}

	public static function public(string $identifier, Type $type): self
	{
		return new self(self::PUBLIC_ACCESS, $identifier, self::NO_VALUE, $type);
	}

	public function __construct(
		string $access,
		string $identifier,
		$value = self::NO_VALUE,
		Type $type = null,
		PhpDocComment $comment = null
	) {
		if ($type && !$comment && (PHP_VERSION_ID < 70400 || $type->needDockBlockTypeHint())) {
			$comment = PhpDocComment::var($type);
		}
		$this->comment = $comment;
		$this->access = $access;
		$this->identifier = Identifier::simple($identifier);
		$this->initializedValue = $value;
		$this->type = $type ?? Type::empty();
	}

	public function setStatic(): self
	{
		$this->static = true;
		return $this;
	}

	/**
	 * Returns the complete source code for the variable
	 *
	 * @return string
	 */
	public function getSource(): string
	{
		$ret = '';

		if ($this->comment) {
			$ret .= $this->getSourceRow($this->comment->getSource());
		}

		$dec = $this->access;
		$dec .= $this->static ? ' static' : '';
		if (PHP_VERSION_ID >= 70400 && !$this->type->needDockBlockTypeHint()) {
			$dec .= ' ' . $this->type->getTypeHint();
		}

		$dec .= ' $' . $this->identifier->getName();
		if ($this->initializedValue !== self::NO_VALUE) {
			$dec .= ' = ' . rtrim($this->raw ? $this->initializedValue : FormatValue::format($this->initializedValue), PHP_EOL);
		}
		if (substr($dec, -1) !== ';') {
			$dec .= ';';
		}

		$sourceRow = $this->getSourceRow($dec);
		// Strip unnecessary null as default value
		$sourceRow = preg_replace('@\s+=\s+null;@', ';', $sourceRow);

		return $ret . $sourceRow;
	}

	public function setInitializedValue(string $initializedValue): PhpVariable
	{
		$this->initializedValue = $initializedValue;
		return $this;
	}

	public function getInitializedValue(): string
	{
		return $this->initializedValue === self::NO_VALUE ? '' : $this->initializedValue;
	}

	public function enableRawValue(): self
	{
		$this->raw = true;
		return $this;
	}

	public function getType(): Type
	{
		return $this->type;
	}
}
