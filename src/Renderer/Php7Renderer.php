<?php declare(strict_types=1);

namespace Stefna\PhpCodeBuilder\Renderer;

use Stefna\PhpCodeBuilder\CodeHelper\CodeInterface;
use Stefna\PhpCodeBuilder\Exception\InvalidCode;
use Stefna\PhpCodeBuilder\FlattenSource;
use Stefna\PhpCodeBuilder\FormatValue;
use Stefna\PhpCodeBuilder\PhpAttribute;
use Stefna\PhpCodeBuilder\PhpClass;
use Stefna\PhpCodeBuilder\PhpConstant;
use Stefna\PhpCodeBuilder\PhpDocComment;
use Stefna\PhpCodeBuilder\PhpDocElementFactory;
use Stefna\PhpCodeBuilder\PhpEnum;
use Stefna\PhpCodeBuilder\PhpFile;
use Stefna\PhpCodeBuilder\PhpFunction;
use Stefna\PhpCodeBuilder\PhpInterface;
use Stefna\PhpCodeBuilder\PhpMethod;
use Stefna\PhpCodeBuilder\PhpParam;
use Stefna\PhpCodeBuilder\PhpTrait;
use Stefna\PhpCodeBuilder\PhpVariable;
use Stefna\PhpCodeBuilder\ValueObject\EnumBackedCase;
use Stefna\PhpCodeBuilder\ValueObject\Identifier;
use Stefna\PhpCodeBuilder\ValueObject\Type;

class Php7Renderer implements FullRendererInterface
{
	/** @var list<string> */
	protected array $invalidReturnTypes = [
		'mixed',
		'resource',
		'static',
		'object',
	];

	/**
	 * @return array<int, string|array<int, string>>
	 */
	public function renderFile(PhpFile $file): array
	{
		$declaration = '<?php';
		if ($file->isStrict()) {
			$declaration .= ' declare(strict_types=1);';
		}
		$ret = [];
		$ret[] = $declaration;
		$ret[] = '';

		$namespace = $file->getNamespace() ?? '';

		$classes = $file->getClasses();
		if (count($classes) === 1) {
			$classes->rewind();
			/** @var Identifier $class */
			$class = $classes->current();
			if ($class->getNamespace()) {
				$namespace .= $class->getNamespace();
				$namespace = trim($namespace, '\\');
			}
		}

		if ($namespace) {
			$ret[] = 'namespace ' . $namespace . ';';
			$ret[] = '';
		}

		foreach ($classes as $identifier) {
			/** @var PhpTrait|PhpClass $class */
			$class = $classes[$identifier];
			foreach ($class->getUses() as $useIdentifier) {
				$file->addUse($useIdentifier);
			}
		}

		$uses = $file->getUse();
		if (count($uses) > 0) {
			/** @var Identifier $identifier */
			foreach ($uses as $identifier) {
				if ($identifier->getNamespace() === $namespace) {
					// don't need to add use statements for same namespace as file
					continue;
				}
				$useLine = 'use ' . ltrim($identifier->getFqcn(), '\\');
				if ($identifier->getAlias()) {
					$useLine .= ' as ' . $identifier->getAlias();
				}
				$useLine .= ';';
				$ret[] = $useLine;
			}
			$ret[] = '';
		}

		foreach ($classes as $identifier) {
			/** @var PhpTrait|PhpClass|PhpInterface $class */
			$class = $classes[$identifier];
			if ($class instanceof PhpEnum) {
				$source = $this->renderEnum($class);
			}
			elseif ($class instanceof PhpInterface) {
				$source = $this->renderInterface($class);
			}
			elseif ($class instanceof PhpClass) {
				$source = $this->renderClass($class);
			}
			else {
				$source = $this->renderTrait($class);
			}
			$ret = FlattenSource::applySourceOn($source, $ret);
		}

		$functions = $file->getFunctions();
		if (count($functions) > 0) {
			foreach ($functions as $function) {
				$ret = FlattenSource::applySourceOn($this->renderFunction($function), $ret);
			}
		}

		if ($file->getSource()) {
			$ret = FlattenSource::applySourceOn($file->getSource(), $ret);
		}

		return $ret;
	}

	public function renderEnum(PhpEnum $enum): array
	{
		$enum->setFinal();
		$tryFromCtorSource = [];

		foreach ($enum->getCases() as $case) {
			$value = $case instanceof EnumBackedCase ? $case->getValue() : null;
			$enum->addConstant(PhpConstant::public($case->getName(), $value)->setCase(PhpConstant::CASE_NONE));
			$tryFromCtorSource[] = 'if ($value === self::' . $case->getName() . ') {';
			$tryFromCtorSource[] = ['return new self($value);'];
			$tryFromCtorSource[] = '}';
		}

		$tryFromCtorSource[] = 'return null;';

		$fromCtor = PhpMethod::public('from', [
			new PhpParam('value', Type::fromString('string')),
		], [
			'$self = self::tryFrom($value);',
			'if ($self) {',
			['return $self;'],
			'}',
			'throw new \ValueError(\'Enum not found: \' . $value);',
		], Type::fromString('self'));
		$fromCtor->setStatic();
		$enum->addMethod($fromCtor);
		$tryFromCtor = PhpMethod::public('tryFrom', [
			new PhpParam('value', Type::fromString('string')),
		], $tryFromCtorSource, Type::fromString('self|null'));
		$tryFromCtor->setStatic();
		$enum->addMethod($tryFromCtor);

		$ctor = PhpMethod::constructor([
			new PhpParam(
				'value',
				Type::fromString('string'),
				autoCreateVariable: true,
				autoCreateVariableAccess: PhpVariable::PUBLIC_ACCESS,
			),
		], [], true);
		$ctor->setAccess(PhpMethod::PRIVATE_ACCESS);
		$enum->addMethod($ctor);
		$enum->addMethod(PhpMethod::public('__toString', [], [
			'return (string)$this->value;',
		], Type::fromString('string')));


		return $this->renderClass($enum);
	}

	/**
	 * @return list<string>
	 */
	protected function formatClassModifiers(PhpClass $class): array
	{
		$modifiers = [];
		if ($class->isFinal()) {
			$modifiers[] = 'final';
		}
		elseif ($class->isAbstract()) {
			$modifiers[] = 'abstract';
		}

		return $modifiers;
	}

	/**
	 * @return array<int, string|array<int, string>>
	 */
	public function renderClass(PhpClass $class): array
	{
		$ret = FlattenSource::applySourceOn($this->renderComment($class->getComment()), []);
		foreach ($class->getAttributes() as $attribute) {
			$ret = FlattenSource::applySourceOn($this->renderAttribute($attribute), $ret);
		}

		$declaration = $this->formatClassModifiers($class);
		$declaration[] = 'class';
		$declaration[] = $class->getIdentifier()->getName();
		$extends = $class->getExtends();
		if ($extends) {
			$declaration[] = 'extends';
			$declaration[] = $extends->getName();
		}

		$implements = $class->getImplements();
		if ($implements) {
			$declaration[] = 'implements';
			$implementsDeclaration = [];
			$multiline = count($implements) > 2;
			foreach ($implements as $identifier) {
				if ($multiline) {
					$implementsDeclaration[] = $identifier->toString() . ',';
				}
				else {
					$implementsDeclaration[] = $identifier->toString();
				}
			}
			if ($multiline) {
				$ret[] = implode(' ', $declaration);
				$implementsDeclaration[array_key_last($implementsDeclaration)] = substr(
					$implementsDeclaration[array_key_last($implementsDeclaration)],
					0,
					-1,
				);
				$ret[] = $implementsDeclaration;
			}
			else {
				$declaration[] = implode(', ', $implementsDeclaration);
				$ret[] = implode(' ', $declaration);
			}
		}
		else {
			$ret[] = implode(' ', $declaration);
		}

		$ret[] = '{';
		$ret = FlattenSource::applySourceOn($this->renderObjectBody($class), $ret);
		$ret[] = '}';

		return $ret;
	}

	/**
	 * @return array<int, string|array<int, string>>
	 */
	public function renderInterface(PhpInterface $interface): array
	{
		$ret = FlattenSource::applySourceOn($this->renderComment($interface->getComment()), []);

		$declaration = 'interface ' . $interface->getIdentifier()->getName();
		$extends = $interface->getExtends();
		if ($extends) {
			$declaration .= ' extends';
			$extendDeclaration = [];
			$multiline = count($extends) > 2;
			foreach ($extends as $identifier) {
				if ($multiline) {
					$extendDeclaration[] = $identifier->toString() . ',';
				}
				else {
					$extendDeclaration[] = $identifier->toString();
				}
			}
			if ($multiline) {
				$ret[] = $declaration;
				$extendDeclaration[array_key_last($extendDeclaration)] = substr(
					$extendDeclaration[array_key_last($extendDeclaration)],
					0,
					-1,
				);
				$ret[] = $extendDeclaration;
			}
			else {
				$ret[] = $declaration . ' ' . implode(', ', $extendDeclaration);
			}
		}
		else {
			$ret[] = $declaration;
		}

		$ret[] = '{';
		$ret = FlattenSource::applySourceOn($this->renderObjectBody($interface), $ret);
		$ret[] = '}';

		return $ret;
	}

	/**
	 * @return array<int, string|array<int, string>>
	 */
	public function renderTrait(PhpTrait $trait): array
	{
		$ret = FlattenSource::applySourceOn($this->renderComment($trait->getComment()), []);

		$ret[] = 'trait ' . $trait->getIdentifier()->getName();
		$ret[] = '{';
		$ret = FlattenSource::applySourceOn($this->renderObjectBody($trait), $ret);
		$ret[] = '}';

		return $ret;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function renderVariable(PhpVariable $variable, ?PhpTrait $parent = null): array|null
	{
		/** @var array<int, mixed> $ret */
		$ret = [];

		$comment = $variable->getComment();
		if (!$comment && !$variable->getType()->isEmpty()) {
			$comment = PhpDocComment::var($variable->getType());
		}
		if ($comment) {
			$ret = FlattenSource::applySourceOn($this->renderComment($comment), $ret);
		}

		$line = [];
		$access = $variable->getAccess();
		if (!$access) {
			$access = 'public';
		}
		$line[] = $access;

		if ($variable->isStatic()) {
			$line[] = 'static';
		}

		$line[] = '$' . $variable->getIdentifier()->getName();

		return $this->formatVariableValue($variable, implode(' ', $line), $ret);
	}

	/**
	 * @param array<int, mixed> $ret
	 * @return array<int, mixed>
	 */
	protected function formatVariableValue(PhpVariable $variable, string $lineStr, array $ret): array
	{
		if ($variable->getInitializedValue() === PhpVariable::NO_VALUE) {
			$ret[] = $lineStr . ';';
			return $ret;
		}

		$lineStr .= ' = ';
		$value = FormatValue::format($variable->getInitializedValue());
		if (!is_array($value)) {
			$lineStr .= $value;
			$ret[] = $lineStr . ';';
			return $ret;
		}

		if (count($value) === 1 && !is_array($value[0])) {
			$ret[] = $lineStr . $value[0] . ';';
			return $ret;
		}
		$lineStr .= array_shift($value);
		$ret[] = $lineStr;
		$ret = FlattenSource::applySourceOn($value, $ret);
		$lastKey = (int)array_key_last($ret);
		if (is_string($ret[$lastKey])) {
			$ret[$lastKey] .= ';';
		}

		return $ret;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function renderMethod(PhpMethod $method): array
	{
		if ($method->isConstructor() && $method->doConstructorAutoAssign()) {
			$body = $method->getBody();
			if ($body instanceof CodeInterface) {
				$body = $body->getSourceArray();
			}

			foreach ($method->getParams() as $param) {
				$var = $param->getVariable();
				if ($var) {
					$body[] = sprintf('$this->%s = $%s;', $param->getName(), $param->getName());
				}
			}
			$method->setBody($body);
		}

		return $this->renderFunction($method);
	}

	/**
	 * @return array<int, mixed>
	 */
	public function renderFunction(PhpFunction $function): array
	{
		Type::setInvalidReturnTypes($this->invalidReturnTypes);
		$ret = [];

		if (!$function->getComment()) {
			$function->setComment(new PhpDocComment(''));
		}

		if ($function->getReturnType()->needDockBlockTypeHint()) {
			$function->getComment()?->setReturn(
				PhpDocElementFactory::getReturn($function->getReturnType()->getDocBlockTypeHint() ?? '')
			);
		}

		$ret = FlattenSource::applySourceOn($this->renderFunctionSignature($function), $ret);
		$isAbstract = $function instanceof PhpMethod && (
			$function->isAbstract() ||
			$function->getParent() instanceof PhpInterface
		);

		if ($isAbstract) {
			return $ret;
		}

		$ret[] = $function->getBody();
		$ret[] = '}';

		return $ret;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function renderConstant(PhpConstant $constant): array
	{
		$ret = [];
		$line = [];
		$access = $constant->getAccess();
		if ($access) {
			$line[] = $access;
		}

		$line[] = 'const';
		$line[] = $constant->getName();
		$line[] = '=';
		$lineStr = implode(' ', $line);

		$value = FormatValue::format($constant->getValue());
		if (is_array($value)) {
			if (count($value) > 1) {
				$lineStr .= ' ' . array_shift($value);
				$ret[] = $lineStr;
			}
			$ret = FlattenSource::applySourceOn($value, $ret);
			$lastKey = (int)array_key_last($ret);
			if (is_string($ret[$lastKey])) {
				$ret[$lastKey] .= ';';
			}
		}
		else {
			$lineStr .= ' ' . $value;
			$ret[] = $lineStr . ';';
		}

		return $ret;
	}

	/**
	 * @return array<int, string|array<int, string>>|string
	 */
	public function renderParams(PhpFunction $function, PhpParam ...$params): array|string
	{
		Type::setInvalidReturnTypes($this->invalidReturnTypes);
		$docBlock = $function->getComment() ?? new PhpDocComment();
		$parameterStrings = [];
		foreach ($params as $param) {
			if ($param->getType()->needDockBlockTypeHint()) {
				$docBlock->addParam(PhpDocElementFactory::getParam($param->getType(), $param->getName()));
			}
			$parameterStrings[] = $this->renderParam($param);
		}

		if (count($params) > 2) {
			for ($i = 0, $l = count($parameterStrings) - 1; $i < $l; $i++) {
				$parameterStrings[$i] .= ',';
			}
			return $parameterStrings;
		}

		return implode(', ', $parameterStrings);
	}

	public function renderParam(PhpParam $param): string
	{
		$ret = '';
		if ($param->getType()->getTypeHint()) {
			$ret .= $param->getType()->getTypeHint();
		}

		$ret .= ' ';
		if ($param->isVariadic()) {
			$ret .= '...';
		}
		$ret .= '$' . $param->getName();
		if ($param->getValue() !== PhpParam::NO_VALUE) {
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
	public function renderComment(?PhpDocComment $comment): array
	{
		if (!$comment) {
			return [];
		}
		$returnArray = ['/**'];

		$description = '';
		if ($comment->getDescription()) {
			$preDescription = trim($comment->getDescription());
			$lines = explode(PHP_EOL, $preDescription);
			foreach ($lines as $line) {
				$returnArray[] = ' ' . trim('* ' . $line);
				$description .= trim('* ' . $line) . PHP_EOL;
			}
		}

		if ($comment->getVar() !== null) {
			if (!$description) {
				return ["/** @var {$comment->getVar()->getDataType()->getDocBlockTypeHint()} */"];
			}
			$returnArray[] = ' *';
			$returnArray[] = rtrim($comment->getVar()->getSource(), PHP_EOL);
		}

		if ($description) {
			$returnArray[] = ' *';
		}

		$haveTags = false;

		if (count($comment->getParams()) > 0) {
			$haveTags = true;
			foreach ($comment->getParams() as $param) {
				$returnArray[] = rtrim($param->getSource(), PHP_EOL);
			}
		}
		if (count($comment->getThrows()) > 0) {
			$haveTags = true;
			foreach ($comment->getThrows() as $throws) {
				$returnArray[] = rtrim($throws->getSource(), PHP_EOL);
			}
		}
		if ($comment->getAuthor() !== null) {
			$haveTags = true;
			$returnArray[] = rtrim($comment->getAuthor()->getSource(), PHP_EOL);
		}
		if ($comment->getReturn() !== null) {
			$haveTags = true;
			$returnArray[] = rtrim($comment->getReturn()->getSource(), PHP_EOL);
		}
		foreach ($comment->getMethods() as $method) {
			$haveTags = true;
			$returnArray[] = rtrim($method->getSource(), PHP_EOL);
		}

		if (!$haveTags) {
			array_pop($returnArray);
		}
		if ($returnArray) {
			$returnArray[] = ' */';
		}

		return $returnArray;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function renderFunctionSignature(PhpFunction $function): array
	{
		$isAbstract = false;
		$line = [];
		if ($function instanceof PhpMethod) {
			$isAbstract = $function->getParent() instanceof PhpInterface;
			if ($function->isAbstract()) {
				$line[] = 'abstract';
				$isAbstract = true;
			}
			if ($function->isFinal()) {
				$line[] = 'final';
			}
			$line[] = $function->getAccess();
			if ($function->isStatic()) {
				$line[] = 'static';
			}
		}

		$line[] = 'function';
		$line[] = $function->getIdentifier()->toString();
		$lineStr = implode(' ', $line);
		$paramSource = $this->renderParams($function, ...$function->getParams());
		$ret = FlattenSource::applySourceOn($this->renderComment($function->getComment()), []);

		$singleLine = false;
		if (is_string($paramSource)) {
			$lineStr .= '(' . $paramSource . ')';
			$ret[] = $lineStr;
			$singleLine = true;
		}
		else {
			$lineStr .= '(';
			$ret[] = $lineStr;
			$ret[] = $paramSource;
			$ret[] = ')';
		}

		$returnTypeHint = $this->renderFunctionReturnType($function);

		if ($returnTypeHint) {
			$lastKey = (int)array_key_last($ret);
			if (!is_string($ret[$lastKey])) {
				throw InvalidCode::invalidType();
			}
			$ret[$lastKey] .= ': ' . $returnTypeHint;
		}

		$lastKey = (int)array_key_last($ret);
		if (!is_string($ret[$lastKey])) {
			throw InvalidCode::invalidType();
		}
		if ($isAbstract) {
			$ret[$lastKey] .= ';';
		}
		elseif ($singleLine) {
			$ret[] = '{';
		}
		else {
			$ret[$lastKey] .= ' {';
		}

		return $ret;
	}

	/**
	 * @return array<int, string|array<int, string>>
	 */
	public function renderObjectBody(PhpTrait|PhpClass|PhpInterface $obj): array
	{
		$classBody = [];

		$addNewLine = false;
		$traits = $obj->getTraits();
		if ($traits) {
			$addNewLine = true;
			foreach ($traits as $trait) {
				$classBody[] = ['use ' . $trait->toString() . ';'];
			}
		}

		$constants = $obj->getConstants();
		if (count($constants)) {
			if ($addNewLine) {
				$classBody[] = '';
			}
			foreach ($constants as $identifier) {
				$classBody[] = $this->renderConstant($constants[$identifier]);
			}
			$addNewLine = true;
		}

		$constructorMethod = $obj->getMethod('__construct');
		$constructor = null;
		if ($constructorMethod) {
			$constructor = $this->renderMethod($constructorMethod);
		}

		$variables = $obj->getVariables();
		if (count($variables)) {
			if ($addNewLine) {
				$classBody[] = '';
			}
			$addedVars = 0;
			foreach ($variables as $identifier) {
				/** @var PhpVariable $var */
				$var = $variables[$identifier];
				$source = $this->renderVariable($var, $obj);
				if ($source !== null) {
					$addedVars++;
					$classBody[] = $source;
				}
				$setter = $var->getSetter($obj);
				$getter = $var->getGetter();
				if ($setter && !$obj->hasMethod($setter->getIdentifier())) {
					$obj->addMethod($setter);
				}
				if ($getter && !$obj->hasMethod($getter->getIdentifier())) {
					$obj->addMethod($getter);
				}
			}
			if ($addedVars > 0) {
				$addNewLine = true;
			}
		}

		$methods = $obj->getMethods();
		if (count($methods)) {
			if ($addNewLine) {
				$classBody[] = '';
			}
			if ($constructor) {
				$classBody[] = $constructor;
				$classBody[] = '';
			}
			/** @var Identifier $identifier */
			foreach ($methods as $identifier) {
				if ($identifier->toString() === '__construct') {
					// render constructor separately so it's always on top
					continue;
				}
				$classBody[] = $this->renderMethod($methods[$identifier]);
				$classBody[] = '';
			}
			array_pop($classBody);
		}
		return $classBody;
	}

	public function render(object|array $obj): string
	{
		$source = null;
		if (is_array($obj)) {
			$source = $obj;
		}
		elseif ($obj instanceof CodeInterface) {
			$source = $obj->getSourceArray();
		}
		elseif ($obj instanceof PhpInterface) {
			$source = $this->renderInterface($obj);
		}
		elseif ($obj instanceof PhpEnum) {
			$source = $this->renderEnum($obj);
		}
		elseif ($obj instanceof PhpClass) {
			$source = $this->renderClass($obj);
		}
		elseif ($obj instanceof PhpTrait) {
			$source = $this->renderTrait($obj);
		}
		elseif ($obj instanceof PhpConstant) {
			$source = $this->renderConstant($obj);
		}
		elseif ($obj instanceof PhpVariable) {
			$source = $this->renderVariable($obj);
		}
		elseif ($obj instanceof PhpMethod) {
			$source = $this->renderMethod($obj);
		}
		elseif ($obj instanceof PhpFunction) {
			$source = $this->renderFunction($obj);
		}
		elseif ($obj instanceof PhpParam) {
			$source = $this->renderParam($obj);
		}
		elseif ($obj instanceof PhpFile) {
			$source = $this->renderFile($obj);
		}

		if ($source !== null) {
			return FlattenSource::source($source);
		}

		throw new \BadMethodCallException('Unknown object type. Don\'t know how to render');
	}

	public function renderAttribute(PhpAttribute $attr): array
	{
		// not-supported
		return [];
	}

	protected function renderFunctionReturnType(PhpFunction $function): string
	{
		return $function->getReturnType()->getTypeHint() ?? '';
	}
}
