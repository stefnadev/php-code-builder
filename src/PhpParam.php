<?php declare(strict_types=1);

namespace Stefna\PhpCodeBuilder;

use Stefna\PhpCodeBuilder\ValueObject\Identifier;
use Stefna\PhpCodeBuilder\ValueObject\Type;

class PhpParam
{
	use AttributeAware;

	public const NO_VALUE = '__PhpParam_NoValue__';

	/**
	 * Optional variable connected to param
	 */
	private ?PhpVariable $variable = null;
	/**
	 * Optional connected method or function
	 */
	private null|PhpFunction|PhpMethod $parent = null;
	protected bool $variadic = false;

	public static function fromVariable(PhpVariable $var): self
	{
		$self = new self($var->getIdentifier()->getName(), $var->getType());
		$self->variable = $var;
		return $self;
	}

	public function __construct(
		protected string $name,
		protected Type $type,
		protected mixed $value = self::NO_VALUE,
		protected bool $autoCreateVariable = false,
		protected bool $autoCreateVariableSetter = false,
		protected bool $autoCreateVariableGetter = false,
		protected string $autoCreateVariableAccess = PhpVariable::PRIVATE_ACCESS,
	) {}

	public function getVariable(): ?PhpVariable
	{
		if (!$this->variable && $this->autoCreateVariable) {
			$type = $this->type;
			if ($this->isVariadic()) {
				$type = Type::fromString($type->getType() . '[]');
			}
			$this->variable = new PhpVariable(
				$this->autoCreateVariableAccess,
				Identifier::simple($this->name),
				$type,
				autoSetter: $this->autoCreateVariableSetter,
				autoGetter: $this->autoCreateVariableGetter,
			);
		}
		return $this->variable;
	}

	public function getParent(): PhpFunction|PhpMethod|null
	{
		return $this->parent;
	}

	public function setParent(PhpFunction|PhpMethod $parent): void
	{
		$this->parent = $parent;
		if (
			$this->variable &&
			$parent instanceof PhpMethod &&
			$parent->doConstructorAutoAssign()
		) {
			$this->variable->setPromoted();
		}
	}

	public function getName(): string
	{
		if (str_starts_with($this->name, '$')) {
			$this->name = substr($this->name, 1);
		}
		return $this->name;
	}

	public function getType(): Type
	{
		return $this->type;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function setType(Type $type): void
	{
		$this->type = $type;
	}

	public function setValue(mixed $value): void
	{
		$this->value = $value;
	}

	public function allowNull(): void
	{
		$this->type->addUnion('null');
	}

	public function isNullable(): bool
	{
		return $this->type->isNullable();
	}

	public function __clone()
	{
		$this->type = clone $this->type;
	}

	public function markAsVariadic(bool $flag = true): void
	{
		$this->variadic = $flag;
	}

	/**
	 * @return bool
	 */
	public function isVariadic(): bool
	{
		return $this->variadic;
	}
}
