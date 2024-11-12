<?php declare(strict_types=1);

namespace Stefna\PhpCodeBuilder\Renderer;

use Stefna\PhpCodeBuilder\CodeHelper\CodeInterface;
use Stefna\PhpCodeBuilder\Exception\InvalidCode;
use Stefna\PhpCodeBuilder\FormatValue;
use Stefna\PhpCodeBuilder\PhpAttribute;
use Stefna\PhpCodeBuilder\PhpClass;
use Stefna\PhpCodeBuilder\PhpDocComment;
use Stefna\PhpCodeBuilder\PhpDocElementFactory;
use Stefna\PhpCodeBuilder\PhpFunction;
use Stefna\PhpCodeBuilder\PhpMethod;
use Stefna\PhpCodeBuilder\PhpParam;
use Stefna\PhpCodeBuilder\PhpTrait;
use Stefna\PhpCodeBuilder\PhpVariable;
use Stefna\PhpCodeBuilder\ValueObject\Type;

class Php8Renderer extends Php74Renderer
{
	protected array $invalidReturnTypes = [
		'resource',
	];

	protected function formatTypeHint(?Type $type): ?string
	{
		if (!$type) {
			return null;
		}

		if ($type->isUnion()) {
			$typeHint = [];
			if ($type->isNullable()) {
				$typeHint[] = 'null';
			}
			foreach ($type->getUnionTypes() as $unionType) {
				$typeHint[] = $unionType->getTypeHint();
			}
			return implode('|', $typeHint);
		}

		if ($type->getType() === 'mixed') {
			return 'mixed';
		}

		return $type->getTypeHint();
	}

	public function renderClass(PhpClass $class): array
	{
		$constructorMethod = $class->getMethod('__construct');
		if ($constructorMethod && $constructorMethod->doConstructorAutoAssign()) {
			$params = $constructorMethod->getParams();
			foreach ($params as $param) {
				if ($this->canPromoteParam($param)) {
					continue;
				}
				$param->getVariable()?->setPromoted(false);
			}
		}

		return parent::renderClass($class);
	}

	/**
	 * @return array<int, mixed>|null
	 */
	public function renderVariable(PhpVariable $variable, ?PhpTrait $parent = null): array|null
	{
		if ($variable->isPromoted() && $this->canPromoteParam($variable->getType())) {
			return null;
		}

		$type = $variable->getType();
		if ($type->getType() === 'mixed') {
			$variable->getComment()?->removeVar();
		}
		if ($type->isUnion() && $type->isNullable() && $variable->getInitializedValue() === PhpVariable::NO_VALUE) {
			$variable->setInitializedValue(null);
		}

		return parent::renderVariable($variable, $parent);
	}

	/**
	 * @return array<int, mixed>
	 */
	public function renderParams(PhpFunction $function, PhpParam ...$params): array|string
	{
		$multiLine = false;
		$includeVariableAttributes = false;
		if (
			$function instanceof PhpMethod &&
			$function->isConstructor() &&
			$function->doConstructorAutoAssign()
		) {
			$multiLine = true;
			$includeVariableAttributes = true;
		}

		$docBlock = $function->getComment() ?? new PhpDocComment();
		$parameterStrings = [];
		foreach ($params as $param) {
			$attributes = [
				...$param->getAttributes(),
				...($includeVariableAttributes ? ($param->getVariable()?->getAttributes() ?? []) : []),
			];
			if ($param->getType()->needDockBlockTypeHint() && !$param->getType()->isUnion()) {
				$docBlock->addParam(PhpDocElementFactory::getParam($param->getType(), $param->getName()));
			}
			$paramStr = $this->renderParam($param);
			if ($multiLine && $param->getVariable()) {
				if ($this->canPromoteParam($param)) {
					$paramStr = $this->renderPromotedPropertyModifiers(
						$param,
						$param->getVariable(),
						// @phpstan-ignore-next-line - check happens on line 57
						$function,
					) . ' ' . $paramStr;
				}
				else {
					$paramStr = $this->renderParam($param);
				}
			}
			if ($attributes) {
				$multiLine = true;
				foreach ($attributes as $attr) {
					$parameterStrings[] = $this->renderAttribute($attr)[0];// todo deal with multiline attributes
				}
			}
			$parameterStrings[] = $paramStr . ',';
		}

		if ($multiLine || count($params) > 2) {
			return $parameterStrings;
		}

		return rtrim(implode(' ', $parameterStrings), ',');
	}

	public function renderParam(PhpParam $param): string
	{
		$type = $param->getType();
		$ret = '';
		if ($type->isUnion()) {
			$typeHint = [];
			foreach ($type->getUnionTypes() as $unionType) {
				$typeHint[] = $unionType->getTypeHint();
			}
			if ($type->isNullable() && !in_array('mixed', $typeHint)) {
				array_unshift($typeHint, 'null');
			}

			$ret .= implode('|', $typeHint);
		}
		elseif ($type->getTypeHint()) {
			$ret .= $type->getTypeHint();
		}

		$ret .= ' ';
		if ($param->isVariadic()) {
			$ret .= '...';
		}

		$ret .= '$' . $param->getName();
		if ($param->getValue() !== PhpParam::NO_VALUE) {
			if ($param->isVariadic()) {
				throw new \RuntimeException('Variadic params can\'t have default values');
			}
			$value = FormatValue::format($param->getValue());
			if (is_array($value)) {
				if (count($value) === 1) {
					$value = $value[0];
				}
				else {
					throw new \RuntimeException('Don\'t support multiline values in params');
				}
			}
			$ret .= ' = ' . $value;
		}

		return trim($ret);
	}

	/**
	 * @return array<int, string|array<int, string>>
	 */
	public function renderMethod(PhpMethod $method): array
	{
		if ($method->isConstructor() && $method->doConstructorAutoAssign()) {
			$body = $method->getBody();
			if ($body instanceof CodeInterface) {
				$body = $body->getSourceArray();
			}
			foreach ($method->getParams() as $param) {
				if ($this->canPromoteParam($param)) {
					continue;
				}
				$var = $param->getVariable();
				if ($var) {
					$body[] = sprintf('$this->%s = $%s;', $param->getName(), $param->getName());
				}
			}
			$method->setBody($body);
		}
		$ret = $this->renderFunction($method);

		if ($method->isConstructor()) {
			if (
				is_array($ret[array_key_last($ret) - 1]) &&
				count($ret[array_key_last($ret) - 1]) === 0
			) {
				unset($ret[array_key_last($ret) - 1]);
				unset($ret[array_key_last($ret)]);
				$lastKey = (int)array_key_last($ret);
				if (!is_string($ret[$lastKey])) {
					throw InvalidCode::invalidType();
				}
				$ret[$lastKey] .= '}';
			}
		}

		return $ret;
	}

	protected function renderFunctionReturnType(PhpFunction $function): string
	{
		return $function->getReturnType()->getTypeHint(true) ?? '';
	}

	public function renderComment(?PhpDocComment $comment): array
	{
		if (!$comment) {
			return [];
		}
		$parent = $comment->getParent();
		if ($comment->getVar() && $parent instanceof PhpVariable) {
			if ($parent->getType()->isUnion()) {
				$comment->removeVar();
			}
		}
		return parent::renderComment($comment);
	}

	protected function renderPromotedPropertyModifiers(
		PhpParam $param,
		PhpVariable $variable,
		PhpMethod $method,
	): string {
		return $variable->getAccess() ?: 'protected';
	}

	/**
	 * @return list<string>
	 */
	public function renderAttribute(PhpAttribute $attr): array
	{
		$args = $attr->getArgs();
		$start = '#[' . $attr->getIdentifier()->toString();
		if (!$args) {
			return [
				$start . ']',
			];
		}
		if (count($args) < 3) {
			$argString = implode(', ', $args);
		}
		else {
			$argString = implode(',' . PHP_EOL, $args);
		}
		return [
			$start . '(' . $argString . ')]',
		];
	}

	private function canPromoteParam(PhpParam|Type $param): bool
	{
		$type = $param instanceof Type ? $param : $param->getType();
		if ($type->isEmpty()) {
			return false;
		}
		if ($type->is('callable') || $type->is('resource')) {
			return false;
		}
		if ($param instanceof PhpParam && $param->isVariadic()) {
			return false;
		}
		return true;
	}
}
