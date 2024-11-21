<?php declare(strict_types=1);

namespace ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use Stefna\PhpCodeBuilder\PhpClass;
use Stefna\PhpCodeBuilder\ValueObject\Type;
use PHPUnit\Framework\TestCase;

class TypeTest extends TestCase
{
	public function testNeedDocBlock(): void
	{
		$type = Type::fromString('string|number|null');
		$this->assertTrue($type->isUnion());
		$this->assertTrue($type->isNullable());
		$this->assertTrue($type->needDockBlockTypeHint());
	}

	public function testNullDontMakeUnion(): void
	{
		$type = Type::fromString('string|null');
		$this->assertFalse($type->isUnion());
		$this->assertTrue($type->isNullable());
		$this->assertFalse($type->needDockBlockTypeHint());
	}

	public function testTypeHint(): void
	{
		$type = Type::fromString('string|null');

		$this->assertSame('?string', $type->getTypeHint());
		$this->assertSame('string|null', $type->getDocBlockTypeHint());
	}

	public function testDocBlockTypeHintOrder(): void
	{
		$type = Type::fromString('null|int');

		$this->assertSame('?int', $type->getTypeHint());
		$this->assertSame('int|null', $type->getDocBlockTypeHint());
	}

	public function testParseTypeHint(): void
	{
		$type = Type::fromString('?int');
		$this->assertTrue($type->isNullable());
		$this->assertSame('?int', $type->getTypeHint());
		$this->assertSame('int|null', $type->getDocBlockTypeHint());
	}

	public function testTypeHintAlias(): void
	{
		$type = Type::fromString('double');
		$this->assertFalse($type->isNullable());
		$this->assertSame('float', $type->getTypeHint());
		$this->assertSame('float', $type->getDocBlockTypeHint());
	}

	public function testArrayOf(): void
	{
		$type = Type::fromString('string[]');
		$this->assertTrue($type->isArray());
		$this->assertSame('array', $type->getTypeHint());
		$this->assertSame('string[]', $type->getDocBlockTypeHint());
	}

	public function testNamespacedType(): void
	{
		$type = Type::fromString(PhpClass::class);
		$this->assertTrue($type->isTypeNamespaced());
		$this->assertNotSame(PhpClass::class, $type->getTypeHint());
		$this->assertSame('\\' . PhpClass::class, $type->getTypeHint());
	}

	public function testRootNamespace(): void
	{
		$type = Type::fromString(\DateTimeImmutable::class);
		$this->assertFalse($type->isTypeNamespaced());
		$this->assertSame(\DateTimeImmutable::class, $type->getTypeHint());
		$this->assertNotSame('\\' . \DateTimeImmutable::class, $type->getTypeHint());
	}

	#[DataProvider('invalidInput')]
	public function testInvalidInput(string $input): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$type = Type::fromString($input);
		var_dump($type);
	}

	/**
	 * @return list<string[]>
	 */
	public static function invalidInput(): array
	{
		return [
			['null|null'],
			[''],
			['?'],
		];
	}

	#[DataProvider('invalidTypeHints')]
	public function testInvalidReturnTypeHints(string $input): void
	{
		Type::setInvalidReturnTypes([
			'mixed',
			'resource',
			'static',
			'object',
		]);
		$type = Type::fromString($input);
		$this->assertNull($type->getTypeHint());
	}

	/**
	 * @return list<string[]>
	 */
	public static function invalidTypeHints(): array
	{
		return [
			['mixed'],
			['resource'],
			['static'],
		];
	}

	#[DataProvider('arrayTypes')]
	public function testArrayTypes(string $input, ?string $expectedType): void
	{
		$type = Type::fromString($input);
		$this->assertSame($expectedType, $type->getArrayType());
		if ($expectedType) {
			$this->assertSame('array', $type->getTypeHint());
		}
	}

	/**
	 * @return array<array{string, string|null}>
	 */
	public static function arrayTypes(): array
	{
		return [
			['int[]', 'int'],
			['array<int, string>', 'string'],
			['array<string,int>', 'int'],
			['array<int, DateTime>', 'DateTime'],
			['array<string, string|array<string>>', 'string|array<string>'],
			['Uuid[]', 'Uuid'],
			['string', null],
		];
	}

	public function testUnionWithMixedArray(): void
	{
		$type = Type::fromString('Test[]|Test2');
		$this->assertNull($type->getTypeHint());

		$this->assertSame('Test[]|Test2', $type->getDocBlockTypeHint());
	}

	public function testUnionWithArray(): void
	{
		$type = Type::fromString('Test[]|Test2[]');
		$this->assertSame('array', $type->getTypeHint());

		$this->assertSame('Test[]|Test2[]', $type->getDocBlockTypeHint());
	}

	public function testIsArrayNotEndlessLoop(): void
	{
		$type = Type::fromString('Test[]');
		$type->addUnion(Type::fromString('Test2'));
		$this->assertNull($type->getTypeHint());

		$this->assertSame('Test[]|Test2', $type->getDocBlockTypeHint());
	}

	public function testSimplifiedName(): void
	{
		$type = Type::fromString(self::class);
		$this->assertSame(self::class, $type->getType());
		$type->simplifyName();
		$this->assertTrue($type->isSimplified());
		$this->assertSame('TypeTest', $type->getType());
	}

	public function testUnionTypeDocBlock(): void
	{
		$type = Type::empty();
		$type->addUnion(Type::fromString('string'));
		$type->addUnion(Type::fromString('float'));
		$this->assertSame('string|float', $type->getDocBlockTypeHint());
	}

	public function testUnionTypeWithEmptyBase(): void
	{
		$type = Type::empty();
		$type->addUnion(Type::fromString('string'));
		$type->addUnion(Type::fromString('float'));

		$this->assertCount(2, $type->getUnionTypes());
	}

	public function testRemoveNull(): void
	{
		$type = Type::empty();
		$type->addUnion(Type::fromString('string'));
		$type->addUnion(Type::fromString('float'));
		$type->addUnion(Type::fromString('null'));

		$this->assertSame('string|float|null', $type->getDocBlockTypeHint());
		$this->assertSame('string|float', $type->notNull()->getDocBlockTypeHint());
	}

	public function testNullabilityCheck(): void
	{
		$type = Type::fromString('string|int|null');

		$this->assertTrue($type->isNullable());
	}

	#[DataProvider('nativeTypeProvider')]
	public function testIsNativeCheck(string $strType): void
	{
		$type = Type::fromString($strType);

		$this->assertTrue($type->isNative());
	}

	#[DataProvider('notNativeTypeProvider')]
	public function testIsNotNativeCheck(string $strType): void
	{
		$type = Type::fromString($strType);

		$this->assertFalse($type->isNative());
	}

	/**
	 * @return array<string[]>
	 */
	public static function nativeTypeProvider(): array
	{
		return [
			['string'],
			['float'],
			['bool'],
			['int'],
			['resource'],
			['callable'],
			['object'],
			['string[]'],
			['array<string, callable>'],
		];
	}

	/**
	 * @return array<string[]>
	 */
	public static function notNativeTypeProvider(): array
	{
		return [
			[TestCase::class],
			['TestCase[]'],
			['array<string, TestCase>'],
		];
	}

	public function testIsAliasForDouble(): void
	{
		$type = Type::fromString('double');
		$this->assertTrue($type->is('float'));

		$type->setType('number');
		$this->assertSame('number', $type->getType());
		$this->assertTrue($type->is('float'));
	}

	public function testUnionType(): void
	{
		$type = Type::fromString('int');
		$type->addUnion('string');
		$type->addUnion('null');
		$type->addUnion(\DateTimeImmutable::class);

		$typeHint = [];
		if ($type->isNullable()) {
			$typeHint[] = 'null';
		}
		foreach ($type->getUnionTypes() as $unionType) {
			$typeHint[] = $unionType->getTypeHint();
		}

		$this->assertSame('null|int|string|DateTimeImmutable', implode('|', $typeHint));
	}

	public function testUnionTypeWithMultipleNullableTypes(): void
	{
		$type = Type::fromString('int|null');
		$type->addUnion(Type::fromString('string|null'));
		$type->addUnion('null');
		$complexType = Type::fromString(\DateTimeImmutable::class);
		$complexType->addUnion('null');
		$type->addUnion($complexType);

		$this->assertSame('null|int|string|DateTimeImmutable', $type->getTypeHint(true));
	}

	public function testMixedIsNullable(): void
	{
		Type::setInvalidReturnTypes([]);
		$type = Type::fromString('mixed');

		$this->assertTrue($type->isNullable());

		$this->assertSame('mixed', $type->getDocBlockTypeHint());
		$this->assertSame('mixed', $type->getTypeHint());
	}

	public function testMixedWithNullable(): void
	{
		Type::setInvalidReturnTypes([]);
		$type = Type::fromString('mixed|null|object');

		$this->assertSame('mixed|object', $type->getDocBlockTypeHint());
		$this->assertSame('mixed|object', $type->getTypeHint(true));
	}

	public function testEnumStringValues(): void
	{
		$type = Type::enumString('value1', 'value2', 'value3');
		$this->assertSame("'value1'|'value2'|'value3'", $type->getDocBlockTypeHint());
		$this->assertNull($type->getTypeHint());
		$this->assertNull($type->getTypeHint(true));
	}

	public function testEnumStringValueNullable(): void
	{
		$type = Type::enumString('value1', 'value2', 'value3');
		$type->addUnion('null');
		$this->assertSame("'value1'|'value2'|'value3'|null", $type->getDocBlockTypeHint());
		$this->assertNull($type->getTypeHint());
		$this->assertNull($type->getTypeHint(true));
	}

	public function testEnumStringValueNullableAndClass(): void
	{
		$type = Type::enumString('value1', 'value2', 'value3');
		$type->addUnion('null');
		$type->addUnion(\BackedEnum::class);
		$this->assertSame("'value1'|'value2'|'value3'|BackedEnum|null", $type->getDocBlockTypeHint());
		$this->assertNull($type->getTypeHint());
		$this->assertNull($type->getTypeHint(true));
	}

	public function testDontDoubleRenderUnionTypes(): void
	{
		$type = Type::fromString('array<int>');
		$type->addUnion('array<string>');

		$this->assertSame('array', $type->getTypeHint(true));
	}
}
