<?php declare(strict_types=1);

namespace Stefna\PhpCodeBuilder\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use Stefna\PhpCodeBuilder\FlattenSource;
use Stefna\PhpCodeBuilder\PhpClass;
use Stefna\PhpCodeBuilder\PhpConstant;
use Stefna\PhpCodeBuilder\PhpDocComment;
use Stefna\PhpCodeBuilder\PhpDocElementFactory;
use Stefna\PhpCodeBuilder\PhpFile;
use Stefna\PhpCodeBuilder\PhpMethod;
use Stefna\PhpCodeBuilder\PhpParam;
use Stefna\PhpCodeBuilder\PhpVariable;
use Stefna\PhpCodeBuilder\Renderer\Php74Renderer;
use Stefna\PhpCodeBuilder\Renderer\Php7Renderer;
use Stefna\PhpCodeBuilder\Renderer\Php8Renderer;
use Stefna\PhpCodeBuilder\ValueObject\Identifier;
use Stefna\PhpCodeBuilder\ValueObject\Type;

final class PhpClassTest extends TestCase
{
	use AssertResultTrait;

	private function getTestClass(): PhpClass
	{
		$class = new PhpClass(
			Identifier::fromString(Test\TestClass::class),
			extends: \DateTimeImmutable::class,
			implements: [Identifier::fromString(\JsonSerializable::class)]
		);
		$var = PhpVariable::protected('param1', Type::fromString('string|int'));
		$ctor = PhpMethod::constructor([
			PhpParam::fromVariable($var),
		], [], true);
		$class->addMethod($ctor);

		$var2 = PhpVariable::public('var1', Type::fromString('string|int|null'));
		$class->addVariable($var2);

		$ctor->addParam(new PhpParam('param2', Type::fromString('?int'), autoCreateVariable: true));
		$ctor->addParam(new PhpParam('noneAssigned', Type::fromString('float')));

		return $class;
	}

	public function testClassRenderedWithPhp7()
	{
		$renderer = new Php7Renderer();

		$this->assertSourceResult($renderer->render($this->getTestClass()), 'PhpClassTest.' . __FUNCTION__);
	}

	public function testClassRenderedWithPhp74()
	{
		$renderer = new Php74Renderer();

		$this->assertSourceResult($renderer->renderClass($this->getTestClass()), 'PhpClassTest.' . __FUNCTION__);
	}

	public function testClassRenderedWithPhp8()
	{
		$renderer = new Php8Renderer();

		$this->assertSourceResult($renderer->renderClass($this->getTestClass()), 'PhpClassTest.' . __FUNCTION__);
	}

	public function testLegacyTestComplex()
	{
		$comment = new PhpDocComment('Test Description');
		$comment->addMethod(PhpDocElementFactory::method('DateTime', 'TestClass', 'getDate'));
		$comment->setAuthor(PhpDocElementFactory::getAuthor('test', 'test@stefna.is'));

		$var = PhpVariable::private('random', Type::fromString('int'));
		$class = new PhpClass(
			Identifier::fromString('\Sunkan\Test\TestClass'),
			\ArrayObject::class,
			$comment
		);
		$class->setFinal();
		$class->addVariable($var, true);
		$class->addConstant(PhpConstant::private('SEED', '12'));
		$class->addTrait(NonExistingTrait::class);
		$class->addInterface(\IteratorAggregate::class);

		$renderer = new Php7Renderer();
		$this->assertSourceResult($renderer->renderClass($class), 'PhpClassTest.' . __FUNCTION__);
	}

	public function testAbstractClass()
	{
		$class = new PhpClass(Identifier::fromString(Test\AbstractTest\TestClass::class));
		$class->setAbstract();
		$class->addMethod(PhpMethod::protected('testNonProtectedMethod', [], []));
		$class->addMethod(PhpMethod::protected('testAbstractProtectedMethod', [], [])->setAbstract());

		$renderer = new Php7Renderer();
		$this->assertSourceResult($renderer->renderClass($class), 'PhpClassTest.' . __FUNCTION__);
	}

	public function testAddingAbstractMethodToNoneAbstractClass()
	{
		$this->expectException(\BadMethodCallException::class);

		$class = new PhpClass(Identifier::fromString(Test\TestClass::class));
		$class->addMethod(PhpMethod::protected('testAbstractProtectedMethod', [], [])->setAbstract());
	}
}
