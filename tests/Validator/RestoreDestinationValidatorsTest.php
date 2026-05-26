<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Entity\Host;
use App\Entity\RestoreDestination;
use App\Entity\Storage;
use App\Validator\RestoreDestinationOverwriteRequiresDescription;
use App\Validator\RestoreDestinationOverwriteRequiresDescriptionValidator;
use App\Validator\RestoreDestinationTypeMysql;
use App\Validator\RestoreDestinationTypeMysqlValidator;
use App\Validator\RestoreDestinationTypePostgresql;
use App\Validator\RestoreDestinationTypePostgresqlValidator;
use App\Validator\RestoreDestinationTypeRclone;
use App\Validator\RestoreDestinationTypeRcloneValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\ConstraintValidatorFactoryInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RestoreDestinationValidatorsTest extends TestCase
{
    public function testRcloneRequiresStorageAndTargetPath(): void
    {
        $destination = new RestoreDestination()
            ->setName('x')
            ->setType(RestoreDestination::TYPE_RCLONE);

        $paths = $this->validate($destination, new RestoreDestinationTypeRclone());

        self::assertContains('storage', $paths);
        self::assertContains('targetPath', $paths);
    }

    public function testRcloneAcceptsStorageAndPath(): void
    {
        $destination = new RestoreDestination()
            ->setName('x')
            ->setType(RestoreDestination::TYPE_RCLONE)
            ->setStorage(new Storage())
            ->setTargetPath('bucket:/dir');

        self::assertSame([], $this->validate($destination, new RestoreDestinationTypeRclone()));
    }

    public function testMysqlRequiresHostDatabaseUserPassword(): void
    {
        $destination = new RestoreDestination()
            ->setName('y')
            ->setType(RestoreDestination::TYPE_MYSQL);

        $paths = $this->validate($destination, new RestoreDestinationTypeMysql());

        self::assertContains('host', $paths);
        self::assertContains('targetDatabase', $paths);
        self::assertContains('targetDbUser', $paths);
        self::assertContains('targetDbPassword', $paths);
    }

    public function testMysqlPassesWithAllRequired(): void
    {
        $destination = new RestoreDestination()
            ->setName('y')
            ->setType(RestoreDestination::TYPE_MYSQL)
            ->setHost(new Host())
            ->setTargetDatabase('db')
            ->setTargetDbUser('u')
            ->setTargetDbPassword('p');

        self::assertSame([], $this->validate($destination, new RestoreDestinationTypeMysql()));
    }

    public function testMysqlByoSkipsCredentialChecks(): void
    {
        $destination = new RestoreDestination()
            ->setName('y')
            ->setType(RestoreDestination::TYPE_MYSQL)
            ->setHost(new Host())
            ->setTargetDatabase('db')
            ->setBringOwnCredentials(true);

        $paths = $this->validate($destination, new RestoreDestinationTypeMysql());

        self::assertNotContains('targetDbUser', $paths);
        self::assertNotContains('targetDbPassword', $paths);
    }

    public function testMysqlDirectRequiresDirectHostAndPort(): void
    {
        $destination = new RestoreDestination()
            ->setName('y')
            ->setType(RestoreDestination::TYPE_MYSQL)
            ->setConnectionMode(RestoreDestination::CONNECTION_DIRECT)
            ->setTargetDatabase('db')
            ->setTargetDbUser('u')
            ->setTargetDbPassword('p');

        $paths = $this->validate($destination, new RestoreDestinationTypeMysql());

        self::assertContains('directHost', $paths);
        self::assertContains('directPort', $paths);
        self::assertNotContains('host', $paths);
    }

    public function testMysqlDirectPassesWithDirectEndpoint(): void
    {
        $destination = new RestoreDestination()
            ->setName('y')
            ->setType(RestoreDestination::TYPE_MYSQL)
            ->setConnectionMode(RestoreDestination::CONNECTION_DIRECT)
            ->setDirectHost('db.example.com')
            ->setDirectPort(3306)
            ->setTargetDatabase('db')
            ->setTargetDbUser('u')
            ->setTargetDbPassword('p');

        self::assertSame([], $this->validate($destination, new RestoreDestinationTypeMysql()));
    }

    public function testPostgresqlRequiresHostDatabaseUserPassword(): void
    {
        $destination = new RestoreDestination()
            ->setName('z')
            ->setType(RestoreDestination::TYPE_POSTGRESQL);

        $paths = $this->validate($destination, new RestoreDestinationTypePostgresql());

        self::assertContains('host', $paths);
        self::assertContains('targetDatabase', $paths);
        self::assertContains('targetDbUser', $paths);
        self::assertContains('targetDbPassword', $paths);
    }

    public function testOverwriteRequiresDescription(): void
    {
        $destination = new RestoreDestination()
            ->setName('q')
            ->setType(RestoreDestination::TYPE_MYSQL)
            ->setHost(new Host())
            ->setTargetDatabase('db')
            ->setAllowOverwrite(true);

        self::assertContains('description', $this->validate($destination, new RestoreDestinationOverwriteRequiresDescription()));
    }

    public function testOverwriteWithDescriptionPasses(): void
    {
        $destination = new RestoreDestination()
            ->setName('q')
            ->setType(RestoreDestination::TYPE_MYSQL)
            ->setHost(new Host())
            ->setTargetDatabase('db')
            ->setDescription('approved DR target')
            ->setAllowOverwrite(true);

        self::assertSame([], $this->validate($destination, new RestoreDestinationOverwriteRequiresDescription()));
    }

    /**
     * @return string[] property paths of violations
     */
    private function validate(RestoreDestination $destination, Constraint $constraint): array
    {
        $validator = $this->buildValidator();
        $violations = $validator->validate($destination, $constraint);
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }

    private function buildValidator(): ValidatorInterface
    {
        $factory = new class implements ConstraintValidatorFactoryInterface {
            public function getInstance(Constraint $constraint): ConstraintValidator
            {
                return match (true) {
                    $constraint instanceof RestoreDestinationTypeRclone => new RestoreDestinationTypeRcloneValidator(),
                    $constraint instanceof RestoreDestinationTypeMysql => new RestoreDestinationTypeMysqlValidator(),
                    $constraint instanceof RestoreDestinationTypePostgresql => new RestoreDestinationTypePostgresqlValidator(),
                    $constraint instanceof RestoreDestinationOverwriteRequiresDescription => new RestoreDestinationOverwriteRequiresDescriptionValidator(),
                    default => throw new \LogicException('Unsupported constraint in test factory: '.$constraint::class),
                };
            }
        };

        return Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory($factory)
            ->getValidator();
    }
}
