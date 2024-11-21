<?php declare(strict_types=1);

namespace Stefna\PhpCodeBuilder\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stefna\PhpCodeBuilder\PhpAttribute;
use Stefna\PhpCodeBuilder\PhpClass;
use Stefna\PhpCodeBuilder\PhpMethod;
use Stefna\PhpCodeBuilder\PhpParam;
use Stefna\PhpCodeBuilder\PhpVariable;
use Stefna\PhpCodeBuilder\Renderer\Php7Renderer;
use Stefna\PhpCodeBuilder\Renderer\Php8Renderer;
use Stefna\PhpCodeBuilder\ValueObject\Identifier;
use Stefna\PhpCodeBuilder\ValueObject\Type;

final class PhpMethodTest extends TestCase
{
	use AssertResultTrait;

	public function testPublicSimpleMethod(): void
	{
		$method = PhpMethod::public('testMethod', [
			new PhpParam('param1', Type::fromString('string')),
		], [
			'$this->param = $param1;',
			'return $this;',
		], Type::fromString('self'));

		$renderer = new Php7Renderer();

		$this->assertSourceResult($renderer->renderMethod($method), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testNullableParam(): void
	{
		$param = new PhpParam('param1', Type::fromString('string'));
		$method = PhpMethod::public('testMethod', [$param], [
			'$this->param = $param1;',
			'return $this;',
		], Type::fromString('self'));

		$param->allowNull();

		$renderer = new Php7Renderer();

		$this->assertTrue($param->isNullable());
		$this->assertSourceResult($renderer->renderMethod($method), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testAbstractMethod(): void
	{
		$method = PhpMethod::public('testMethod', [
			new PhpParam('param1', Type::fromString('string')),
		], [], Type::fromString('resource'))->setAbstract();

		$renderer = new Php7Renderer();

		$this->assertSourceResult($renderer->renderMethod($method), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testConstructorWithAutoAssign(): void
	{
		$var2 = PhpVariable::private('test2', Type::fromString('string'));
		$var3 = PhpVariable::protected('test3', Type::fromString('int|string'));
		$ctor = PhpMethod::constructor([
			new PhpParam('test1', Type::fromString('int'), autoCreateVariable: true),
			PhpParam::fromVariable($var2),
			PhpParam::fromVariable($var3),
			new PhpParam('test4', Type::fromString('bool'), true),
		], [], true);

		$renderer = new Php7Renderer();
		$this->assertSourceResult($renderer->renderMethod($ctor), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testConstructorWithAutoAssignPhp8(): void
	{
		$var = PhpVariable::private('test2', Type::fromString('string'));
		$ctor = PhpMethod::constructor([
			new PhpParam('test1', Type::fromString('int'), autoCreateVariable: true),
			PhpParam::fromVariable($var),
			new PhpParam('test3', Type::fromString('int|string|null'), autoCreateVariable: true),
			new PhpParam('test4', Type::fromString('bool'), true),
		], [], true);

		$renderer = new Php8Renderer();
		$this->assertSourceResult($renderer->renderMethod($ctor), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testConstructorSimplePhp8(): void
	{
		$ctor = PhpMethod::constructor([
			new PhpParam('test1', Type::fromString('int')),
		], [
			'// nothing'
		]);

		$renderer = new Php8Renderer();
		$this->assertSourceResult($renderer->renderMethod($ctor), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testConstructorAlwaysMultiLineWhenPromotingPhp8(): void
	{
		$ctor = PhpMethod::constructor([
			new PhpParam('test1', Type::fromString('int'), autoCreateVariable: true),
		], [], true);

		$renderer = new Php8Renderer();
		$this->assertSourceResult($renderer->render($ctor), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testConstructorPromotionWithUnionType(): void
	{
		$type = Type::fromString('int');
		$type->addUnion('string');
		$type->addUnion('null');
		$type->addUnion(\DateTimeImmutable::class);
		$ctor = PhpMethod::constructor([
			new PhpParam(
				'test1',
				$type,
				autoCreateVariable: true,
			),
		], [], true);

		$renderer = new Php8Renderer();
		$this->assertSourceResult($renderer->renderMethod($ctor), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testConstructorPromotingNotRenderingVariablePhp8(): void
	{
		$var = PhpVariable::protected('test', Type::fromString('float'));
		$ctor = PhpMethod::constructor([
			PhpParam::fromVariable($var),
		], [], true);

		$renderer = new Php8Renderer();
		$renderer->renderMethod($ctor);

		$this->assertTrue($var->isPromoted());
	}

	public function testFinalAbstractMethod(): void
	{
		$this->expectException(\BadMethodCallException::class);

		PhpMethod::protected('testMethod', [], [])->setAbstract()->setFinal();
	}

	public function testPrivateAbstractMethod(): void
	{
		$this->expectException(\BadMethodCallException::class);

		PhpMethod::private('testMethod', [], [])->setAbstract();
	}

	public function testFinalPrivateMethod(): void
	{
		$this->expectException(\BadMethodCallException::class);

		PhpMethod::private('testMethod', [], [])->setFinal();
	}

	public function testFinalStaticMethod(): void
	{
		$method = PhpMethod::private('testMethod', [], [])
			->setStatic()
			->setAccess(PhpMethod::PUBLIC_ACCESS)
			->setFinal();

		$renderer = new Php7Renderer();
		$this->assertSourceResult($renderer->renderMethod($method), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testLegacyFunctionTestConstructorMethod(): void
	{
		$method = PhpMethod::constructor([
			new PhpParam('fooIpsumLong', Type::fromString('string')),
			new PhpParam('barIpsumLong', Type::fromString('string|int')),
			new PhpParam('bazIpsumLong', Type::fromString('int')),
			new PhpParam('fozIpsumLong', Type::fromString('string')),
			new PhpParam('alzIpsumLong', Type::fromString('string')),
			new PhpParam('qweIpsumLong', Type::fromString('string')),
			new PhpParam('rtyIpsumLong', Type::fromString('string')),
		], [
			'$this->do = null;',
		]);

		$renderer = new Php7Renderer();
		$this->assertSame([
			'/**',
			' * @param string|int $barIpsumLong',
			' */',
			'public function __construct(',
			[
				'string $fooIpsumLong,',
				'$barIpsumLong,',
				'int $bazIpsumLong,',
				'string $fozIpsumLong,',
				'string $alzIpsumLong,',
				'string $qweIpsumLong,',
				'string $rtyIpsumLong',
			],
			') {',
			['$this->do = null;'],
			'}',
		], $renderer->renderMethod($method));
	}

	public function testLegacyFunctionTestMultilineParamsAbstract(): void
	{
		$func = new PhpMethod('protected', 'test', [
			new PhpParam('fooIpsumLong', Type::fromString('string')),
			new PhpParam('barIpsumLong', Type::fromString('string|int')),
			new PhpParam('bazIpsumLong', Type::fromString('int')),
			new PhpParam('fozIpsumLong', Type::fromString('string')),
			new PhpParam('alzIpsumLong', Type::fromString('string')),
			new PhpParam('qweIpsumLong', Type::fromString('string')),
			new PhpParam('rtyIpsumLong', Type::fromString('string')),
		], [
			'return $foo * $foo;',
		], Type::fromString('int'));
		$func->setAbstract()->setStatic();

		$renderer = new Php7Renderer();
		$this->assertSourceResult($renderer->renderMethod($func), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testAbstractMethodWithBody(): void
	{
		$func = new PhpMethod('protected', 'test', [
			new PhpParam('foo', Type::fromString('string|int')),
		], [
			'return $foo * $foo;',
		], Type::fromString('int'));
		$func->setAbstract();

		$renderer = new Php7Renderer();
		$this->assertSourceResult($renderer->renderMethod($func), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testImmutableSetter(): void
	{
		$var = PhpVariable::protected('test', Type::fromString('string'));
		$setter = PhpMethod::setter($var, immutable: true);

		$renderer = new Php7Renderer();
		$this->assertSourceResult($renderer->renderMethod($setter), 'PhpMethodTest.' . __FUNCTION__);
	}

	public function testConstructorWithPropertyPromotionWithCustomAccess(): void
	{
		$var = PhpVariable::private('test2', Type::fromString('string'));
		$ctor = PhpMethod::constructor([
			new PhpParam('test1', Type::fromString('int'), autoCreateVariable: true),
			PhpParam::fromVariable($var),
			new PhpParam('test3', Type::fromString('int|string|null'), autoCreateVariable: true, autoCreateVariableAccess: PhpVariable::PUBLIC_ACCESS),
			new PhpParam('test4', Type::fromString('bool'), true),
		], [], true);

		$renderer = new Php8Renderer();
		$this->assertSourceResult($renderer->renderMethod($ctor), 'PhpMethodTest.' . __FUNCTION__);
	}

	#[DataProvider('getterPrefixes')]
	public function testAutoGetterPrefix(string $name, Type $type, string $expectedMethodName): void
	{
		$var = PhpVariable::protected($name, $type);
		$method = PhpMethod::getter($var);

		$this->assertSame($expectedMethodName, $method->getIdentifier()->toString());
	}

	/**
	 * @return array<mixed>
	 */
	public static function getterPrefixes(): array
	{
		return [
			['hasError', Type::fromString('bool'), 'hasError'],
			['isAbstract', Type::fromString('bool'), 'isAbstract'],
			['normal', Type::fromString('string'), 'getNormal'],
		];
	}

	public function testStaticReturnTypePhp7(): void
	{
		$method = PhpMethod::public('generate', [], [], Type::fromString('static'));

		$renderer = new Php7Renderer();
		$this->assertSourceResult($renderer->renderMethod($method), 'PhpMethodTest.testStaticReturnTypePhp7');
	}

	public function testStaticReturnTypePhp8(): void
	{
		$method = PhpMethod::public('generate', [], [], Type::fromString('static'));

		$renderer = new Php8Renderer();
		$this->assertSourceResult($renderer->renderMethod($method), 'PhpMethodTest.testStaticReturnTypePhp8');
	}

	public function testParamWithDefaultEmptyArray(): void
	{
		$var = new PhpVariable(
			PhpVariable::PRIVATE_ACCESS,
			Identifier::fromString('test'),
			Type::fromString('string[]'),
		);
		$var->setInitializedValue([]);
		$param = PhpParam::fromVariable($var);
		$param->setValue($var->getInitializedValue());

		$ctor = PhpMethod::constructor([
			$param,
		], []);

		$renderer = new Php8Renderer();
		$this->assertSourceResult($renderer->renderMethod($ctor), 'PhpMethodTest.testParamWithDefaultEmptyArray');
	}

	public function testParamWithMixedAndNullable(): void
	{
		$var = new PhpVariable(
			PhpVariable::PRIVATE_ACCESS,
			Identifier::fromString('test'),
			Type::fromString('mixed|null'),
		);
		$param = PhpParam::fromVariable($var);

		$this->assertSame('mixed $test', (new Php8Renderer())->renderParam($param));
	}

	public function testParamWithMixedAndNullableAndMoreTypes(): void
	{
		$var = new PhpVariable(
			PhpVariable::PRIVATE_ACCESS,
			Identifier::fromString('test'),
			Type::fromString('mixed|null|object'),
		);
		$param = PhpParam::fromVariable($var);

		$this->assertSame('mixed|object $test', (new Php8Renderer())->renderParam($param));
	}

	public function testParamWithMixedAndMarkedAsNullable(): void
	{
		$var = new PhpVariable(
			PhpVariable::PRIVATE_ACCESS,
			Identifier::fromString('test'),
			Type::fromString('mixed'),
		);
		$param = PhpParam::fromVariable($var);
		$param->allowNull();

		$this->assertSame('mixed $test', (new Php8Renderer())->renderParam($param));
	}

	public function testParamAttributeWithPromotedProperties(): void
	{
		$type = Type::fromString('int');
		$param1 = new PhpParam('test1', $type, autoCreateVariable: true);
		$param1->addAttribute(new PhpAttribute(HiddenVariable::class));

		$param2 = new PhpParam('test2', $type, autoCreateVariable: true);
		$param3 = new PhpParam('test3', $type, autoCreateVariable: true);
		$param3->getVariable()?->addAttribute(new PhpAttribute(TestAttribute::class, '1'));
		$ctor = PhpMethod::constructor([
			$param1,
			$param2,
			$param3,
		], [], true);

		$renderer = new Php8Renderer();
		$this->assertSourceResult($renderer->renderMethod($ctor), 'PhpMethodTest.testParamAttributeWithPromotedProperties');
	}

	public function testVariadicParam(): void
	{
		$param = new PhpParam('test', Type::fromString('int'));
		$param->markAsVariadic();

		$this->assertSame('int ...$test', (new Php8Renderer())->renderParam($param));
	}

	public function testVariadicParamInCtorAndPropertyPromotion(): void
	{
		$type = Type::fromString('int');
		$param1 = new PhpParam('test1', $type, autoCreateVariable: true);
		$param1->addAttribute(new PhpAttribute(HiddenVariable::class));

		$param2 = new PhpParam('test2', $type, autoCreateVariable: true);
		$param2->markAsVariadic();
		$ctor = PhpMethod::constructor([
			$param1,
			$param2,
		], [], true);

		$c = new PhpClass('Test');
		$c->addMethod($ctor);
		$renderer = new Php8Renderer();
		$this->assertSourceResult($renderer->renderClass($c), 'PhpMethodTest.testVariadicParamInCtorAndPropertyPromotion');
	}

	public function testRenderUnionResponseTypeForPhp8(): void
	{
		$type = Type::empty();
		$type->addUnion(\DateTimeImmutable::class);
		$type->addUnion(\PDO::class);
		$method = PhpMethod::public('test', [], [], $type);
		$renderer = new Php8Renderer();
		$this->assertSourceResult($renderer->renderMethod($method), 'PhpMethodTest.testRenderUnionResponseTypeForPhp8');
	}

	public function testDontDoubleRenderUnionTypes(): void
	{
		$type = Type::fromString('array<int>');
		$type->addUnion('array<string>');

		$method = PhpMethod::public('test', [
			new PhpParam('test', $type),
		], [], $type);
		$renderer = new Php8Renderer();
		$this->assertSourceResult($renderer->renderMethod($method), 'PhpMethodTest.testDontDoubleRenderUnionTypes');
	}
}
