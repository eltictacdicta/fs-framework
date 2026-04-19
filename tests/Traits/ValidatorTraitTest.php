<?php
/**
 * Tests para ValidatorTrait y ConstraintBuilder.
 *
 * Se crea una clase concreta que usa el trait para poder testearlo.
 */

namespace Tests\Traits;

use PHPUnit\Framework\TestCase;
use FSFramework\Traits\ValidatorTrait;
use FSFramework\Traits\ConstraintBuilder;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Clase de prueba que usa ValidatorTrait con atributos de validación.
 */
class TestModelWithValidation
{
    use ValidatorTrait;

    #[Assert\NotBlank(message: 'El nombre es obligatorio')]
    #[Assert\Length(max: 50, maxMessage: 'Máximo 50 caracteres')]
    public ?string $nombre = null;

    #[Assert\Email(message: 'Email inválido')]
    public ?string $email = null;

    #[Assert\PositiveOrZero]
    public ?float $saldo = null;
}

class ValidatorTraitTest extends TestCase
{
    private const TEST_EMAIL = 'test@test.com';

    // =====================================================================
    // Validación con atributos
    // =====================================================================

    public function testValidModelPasses(): void
    {
        $model = new TestModelWithValidation();
        $model->nombre = 'Juan';
        $model->email = 'juan@example.com';
        $model->saldo = 100.50;

        $this->assertTrue($model->validate());
        $this->assertFalse($model->hasValidationErrors());
    }

    public function testEmptyNameFails(): void
    {
        $model = new TestModelWithValidation();
        $model->nombre = '';
        $model->email = self::TEST_EMAIL;
        $model->saldo = 0;

        $this->assertFalse($model->validate());
        $this->assertTrue($model->hasValidationErrors());

        $errors = $model->getValidationErrors();
        $this->assertArrayHasKey('nombre', $errors);
    }

    public function testNullNameFails(): void
    {
        $model = new TestModelWithValidation();
        // nombre es null por defecto
        $model->email = self::TEST_EMAIL;

        $this->assertFalse($model->validate());
    }

    public function testInvalidEmailFails(): void
    {
        $model = new TestModelWithValidation();
        $model->nombre = 'Test';
        $model->email = 'not-an-email';
        $model->saldo = 0;

        $this->assertFalse($model->validate());

        $errors = $model->getValidationErrors();
        $this->assertArrayHasKey('email', $errors);
    }

    public function testNegativeSaldoFails(): void
    {
        $model = new TestModelWithValidation();
        $model->nombre = 'Test';
        $model->email = self::TEST_EMAIL;
        $model->saldo = -10.0;

        $this->assertFalse($model->validate());

        $errors = $model->getValidationErrors();
        $this->assertArrayHasKey('saldo', $errors);
    }

    // =====================================================================
    // getValidationErrorMessages()
    // =====================================================================

    public function testGetValidationErrorMessages(): void
    {
        $model = new TestModelWithValidation();
        $model->nombre = '';
        $model->email = 'invalid';

        $model->validate();
        $messages = $model->getValidationErrorMessages();

        $this->assertNotEmpty($messages);
        // Debe contener mensajes como strings
        foreach ($messages as $msg) {
            $this->assertNotSame('', $msg);
        }
    }

    // =====================================================================
    // clearValidationErrors()
    // =====================================================================

    public function testClearValidationErrors(): void
    {
        $model = new TestModelWithValidation();
        $model->nombre = '';
        $model->validate();

        $this->assertTrue($model->hasValidationErrors());

        $model->clearValidationErrors();
        $this->assertFalse($model->hasValidationErrors());
    }

    // =====================================================================
    // validateValue() — validación dinámica
    // =====================================================================

    public function testValidateValueWithConstraints(): void
    {
        $model = new TestModelWithValidation();

        $this->assertTrue($model->validateValue('test@email.com', [
            new Assert\NotBlank(),
            new Assert\Email(),
        ]));
    }

    public function testValidateValueFailsWithInvalidData(): void
    {
        $model = new TestModelWithValidation();

        $this->assertFalse($model->validateValue('', [
            new Assert\NotBlank(),
        ]));
    }

    public function testValidatePropertiesChecksOnlySelectedFields(): void
    {
        $model = new TestModelWithValidation();
        $model->nombre = '';
        $model->email = self::TEST_EMAIL;

        $this->assertFalse($model->validateProperties('nombre'));

        $errors = $model->getValidationErrors();
        $this->assertArrayHasKey('nombre', $errors);
        $this->assertArrayNotHasKey('email', $errors);
    }

    // =====================================================================
    // ConstraintBuilder — builder fluido
    // =====================================================================

    public function testConstraintBuilderNotBlank(): void
    {
        $constraints = TestModelWithValidation::constraints()
            ->notBlank()
            ->get();

        $this->assertNotEmpty($constraints);
        $this->assertInstanceOf(Assert\NotBlank::class, $constraints[0]);
    }

    public function testConstraintBuilderChaining(): void
    {
        $constraints = TestModelWithValidation::constraints()
            ->notBlank()
            ->length(min: 1, max: 100)
            ->email()
            ->get();

        $this->assertCount(3, $constraints);
    }

    public function testConstraintBuilderPositive(): void
    {
        $constraints = TestModelWithValidation::constraints()
            ->positive()
            ->get();

        $this->assertCount(1, $constraints);
        $this->assertInstanceOf(Assert\Positive::class, $constraints[0]);
    }

    public function testConstraintBuilderRange(): void
    {
        $constraints = TestModelWithValidation::constraints()
            ->range(min: 0, max: 100)
            ->get();

        $this->assertCount(1, $constraints);
        $this->assertInstanceOf(Assert\Range::class, $constraints[0]);
    }

    public function testConstraintBuilderAdditionalHelpers(): void
    {
        $constraints = TestModelWithValidation::constraints()
            ->notNull()
            ->regex('/^[A-Z]+$/')
            ->positiveOrZero()
            ->choice(['A', 'B'])
            ->date()
            ->dateTime()
            ->iban()
            ->add(new Assert\Url())
            ->get();

        $this->assertCount(8, $constraints);
        $this->assertInstanceOf(Assert\NotNull::class, $constraints[0]);
        $this->assertInstanceOf(Assert\Regex::class, $constraints[1]);
        $this->assertInstanceOf(Assert\PositiveOrZero::class, $constraints[2]);
        $this->assertInstanceOf(Assert\Choice::class, $constraints[3]);
        $this->assertInstanceOf(Assert\Date::class, $constraints[4]);
        $this->assertInstanceOf(Assert\DateTime::class, $constraints[5]);
        $this->assertInstanceOf(Assert\Iban::class, $constraints[6]);
        $this->assertInstanceOf(Assert\Url::class, $constraints[7]);
    }
}
