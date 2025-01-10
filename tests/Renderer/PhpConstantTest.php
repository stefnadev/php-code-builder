<?php declare(strict_types=1);

namespace Stefna\PhpCodeBuilder\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use Stefna\PhpCodeBuilder\PhpConstant;
use Stefna\PhpCodeBuilder\Renderer\Php7Renderer;

final class PhpConstantTest extends TestCase
{
	public function testSimpleConstantWithoutValue(): void
	{
		$const = PhpConstant::private('test');

		$render = new Php7Renderer();

		$this->assertSame(['private const TEST = \'test\';'], $render->renderConstant($const));
	}

	public function testArrayConstant(): void
	{
		$const = PhpConstant::protected('test', [
			'test' => 1,
			'random' => true,
		]);

		$render = new Php7Renderer();

		$this->assertSame([
			'protected const TEST = [',
			[
				'\'test\' => 1,',
				'\'random\' => true,',
			],
			'];',
		], $render->renderConstant($const));
	}

	public function testLowerCase(): void
	{
		$const = new PhpConstant(
			access: PhpConstant::PROTECTED_ACCESS,
			identifier: 'test',
			case: PhpConstant::CASE_LOWER,
		);
		$render = new Php7Renderer();

		$this->assertSame(['protected const test = \'test\';'], $render->renderConstant($const));
	}

	public function testNoCase(): void
	{
		$const = new PhpConstant(
			access: PhpConstant::PUBLIC_ACCESS,
			identifier: 'testCase',
			case: PhpConstant::CASE_NONE,
		);
		$render = new Php7Renderer();

		$this->assertSame(['public const testCase = \'testCase\';'], $render->renderConstant($const));
	}

	public function testUpperCaseNoTransform(): void
	{
		$const = PhpConstant::public(identifier: 'TEST_CASE');
		$render = new Php7Renderer();

		$this->assertSame(['public const TEST_CASE = \'TEST_CASE\';'], $render->renderConstant($const));
	}

	public function testChangeCase(): void
	{
		$const = new PhpConstant(
			access: PhpConstant::PROTECTED_ACCESS,
			identifier: 'test',
			case: PhpConstant::CASE_LOWER,
		);
		$render = new Php7Renderer();

		$this->assertSame(['protected const test = \'test\';'], $render->renderConstant($const));

		$const->setCase(PhpConstant::CASE_UPPER);

		$this->assertSame(['protected const TEST = \'test\';'], $render->renderConstant($const));
	}

	public function testChangeValue(): void
	{
		$const = PhpConstant::public(identifier: 'TEST_CASE');
		$const->setValue('test_value');
		$render = new Php7Renderer();

		$this->assertSame('public const TEST_CASE = \'test_value\';', trim($render->render($const)));
	}

	public function testUpperCaseWithLeadingDigit(): void
	{
		$const = PhpConstant::public(identifier: '3DS');
		$render = new Php7Renderer();

		$this->assertSame(['public const _3DS = \'3DS\';'], $render->renderConstant($const));
	}

	public function testSanitizeConstNameFromSpecialCharacters(): void
	{
		$const = PhpConstant::public(identifier: '>2m');
		$render = new Php7Renderer();

		$this->assertSame(['public const _S_2M = \'>2m\';'], $render->renderConstant($const));
	}
}
