<?php declare(strict_types=1);

namespace CodeHelper;

use PHPUnit\Framework\TestCase;
use Stefna\PhpCodeBuilder\CodeHelper\ArrayCode;
use Stefna\PhpCodeBuilder\CodeHelper\StaticMethodCall;
use Stefna\PhpCodeBuilder\CodeHelper\VariableReference;
use Stefna\PhpCodeBuilder\ValueObject\Identifier;

final class StaticMethodCallTest extends TestCase
{
	public function testSimple()
	{
		$call = new StaticMethodCall(Identifier::fromString(StaticMethodCall::class), 'test');

		$this->assertSame('StaticMethodCall::test()', trim($call->getSource()));
	}

	public function testWithParams()
	{
		$call = new StaticMethodCall(Identifier::fromString(StaticMethodCall::class), 'test', [
			false,
			'string',
		]);

		$this->assertSame('StaticMethodCall::test(false, \'string\')', trim($call->getSource()));
	}

	public function testWithLongParams()
	{
		$call = new StaticMethodCall(Identifier::fromString(StaticMethodCall::class), 'test', [
			'Lorem ipsum long Lorem ipsum long Lorem ipsum long',
			'Lorem ipsum long Lorem ipsum long Lorem ipsum long',
		]);

		$this->assertSame('StaticMethodCall::test(
	\'Lorem ipsum long Lorem ipsum long Lorem ipsum long\',
	\'Lorem ipsum long Lorem ipsum long Lorem ipsum long\'
)', trim($call->getSource()));
	}


	public function testSingleLongArrayParams()
	{
		$call = new StaticMethodCall(Identifier::fromString(StaticMethodCall::class), 'test', [
			new ArrayCode([
				'Lorem ipsum long Lorem ipsum long Lorem ipsum long',
				'Lorem ipsum long Lorem ipsum long Lorem ipsum long',
			]),
		]);

		$this->assertSame('StaticMethodCall::test([
	\'Lorem ipsum long Lorem ipsum long Lorem ipsum long\',
	\'Lorem ipsum long Lorem ipsum long Lorem ipsum long\',
])', trim($call->getSource()));
	}

	public function testManyWithParams()
	{
		$call = new StaticMethodCall(Identifier::fromString(StaticMethodCall::class), 'test', [
			false,
			'string',
			2,
			'longName lorem ipsum',
			'lorem ipsum lorem ipsum lorem ipsum',
		]);

		$this->assertSame('StaticMethodCall::test(
	false,
	\'string\',
	2,
	\'longName lorem ipsum\',
	\'lorem ipsum lorem ipsum lorem ipsum\'
)', trim($call->getSource()));
	}

	public function testWithComplexParams()
	{
		$call = new StaticMethodCall(Identifier::fromString(StaticMethodCall::class), 'test', [
			'site-bearer-token',
			new ArrayCode([
				'type' => 'http',
				'scheme' => 'bearer',
				'description' => 'Valid for site specific endpoints',
			]),
			new VariableReference('testVar'),
		]);

		$this->assertSame('StaticMethodCall::test(\'site-bearer-token\', [
	\'type\' => \'http\',
	\'scheme\' => \'bearer\',
	\'description\' => \'Valid for site specific endpoints\',
], $testVar)', trim($call->getSource()));
	}

	public function testWithLastParamIsArray()
	{
		$call = new StaticMethodCall(Identifier::fromString(StaticMethodCall::class), 'test', [
			'site-bearer-token',
			new ArrayCode([
				'type' => 'http',
				'scheme' => 'bearer',
				'description' => 'Valid for site specific endpoints',
			]),
		]);

		$this->assertSame('StaticMethodCall::test(\'site-bearer-token\', [
	\'type\' => \'http\',
	\'scheme\' => \'bearer\',
	\'description\' => \'Valid for site specific endpoints\',
])', trim($call->getSource()));
	}
}
