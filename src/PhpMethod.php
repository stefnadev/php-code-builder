<?php declare(strict_types=1);

namespace Stefna\PhpCodeBuilder;

use Stefna\PhpCodeBuilder\ValueObject\Type;

/**
 * Class that represents the source code for a method in php
 *
 * @author Fredrik Wallgren <fredrik.wallgren@gmail.com>
 * @author Andreas Sundqvist <andreas@stefna.is>
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
class PhpMethod extends PhpFunction
{
	private $final = false;
	private $static = false;
	private $abstract = false;

	public static function setter(PhpVariable $var, bool $fluent = false): self
	{
		$source = [
			'$this->' . $var->getIdentifier() . ' = $' . $var->getIdentifier(),
		];
		if ($fluent) {
			$source[] = 'return $this;';
		}

		$type = $var->getType();
		$docBlock = null;
		if ($type->needDockBlockTypeHint()) {
			$docBlock = new PhpDocComment();
			$docBlock->addParam(PhpDocElementFactory::getParam($type->getDocBlockTypeHint(), $var->getIdentifier()));
		}

		return new self(self::PUBLIC_ACCESS, 'set' . ucfirst($var->getIdentifier()), [
			new PhpParam($var->getIdentifier(), $type)
		], $source, null, $docBlock);
	}

	public static function getter(PhpVariable $var): self
	{
		return self::public('get' . ucfirst($var->getIdentifier()), [], [
			'return $this->' . $var->getIdentifier() . ';',
		], $var->getType());
	}

	/**
	 * @param PhpParam[] $params
	 */
	public static function constructor(array $params, array $source, bool $autoAssign = false): self
	{
		if ($autoAssign) {
			foreach ($params as $param) {
				$source[] = sprintf('$this->%s = $%s;', $param->getName(), $param->getName());
			}
		}
		return self::public('__construct', $params, $source, Type::empty());
	}

	public static function public(string $identifier, array $params, array $source, Type $type = null): self
	{
		return new self(self::PUBLIC_ACCESS, $identifier, $params, $source, $type ?? Type::empty());
	}

	public static function private(string $identifier, array $params, array $source, Type $type = null): self
	{
		return new self(self::PUBLIC_ACCESS, $identifier, $params, $source, $type ?? Type::empty());
	}

	public static function protected(string $identifier, array $params, array $source, Type $type = null): self
	{
		return new self(self::PUBLIC_ACCESS, $identifier, $params, $source, $type ?? Type::empty());
	}

	/**
	 * @param string $access
	 * @param string $identifier
	 * @param array $params
	 * @param array|string $source
	 * @param Type|null $returnTypeHint
	 * @param PhpDocComment|null $comment
	 */
	public function __construct(
		string $access,
		string $identifier,
		array $params,
		$source,
		?Type $returnTypeHint = null,
		?PhpDocComment $comment = null
	) {
		parent::__construct($identifier, $params, $source, $returnTypeHint ?? Type::empty(), $comment);
		$this->access = $access;
	}

	public function setFinal(): self
	{
		$this->final = true;
		return $this;
	}

	public function setStatic(): self
	{
		$this->static = true;
		return $this;
	}

	public function setAbstract(): self
	{
		$this->abstract = true;
		return $this;
	}

	public function setAccess(string $access): self
	{
		$this->access = $access;
		return $this;
	}

	protected function formatFunctionAccessors(): string
	{
		$ret = '';
		$ret .= $this->abstract ? 'abstract ' : '';
		$ret .= $this->final ? 'final ' : '';
		$ret .= $this->access ? $this->access . ' ' : '';
		$ret .= $this->static ? 'static ' : '';

		return $ret;
	}

	protected function formatFunctionBody(string $ret): string
	{
		if (!$this->abstract) {
			return parent::formatFunctionBody($ret);
		}

		return rtrim(str_replace(' {', '', $ret)) . ';' . PHP_EOL;
	}
}
