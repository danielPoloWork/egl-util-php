<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Persistence\Fixture;

use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Persistence\Repository;

/**
 * A concrete `Repository`, because the class under test is abstract and the interesting
 * behaviour is what a subclass gets.
 *
 * Every method here is a thin `public` wrapper over the `protected` mechanics — that visibility
 * is itself part of the design (a repository exposes the queries it owns, not `fetchAll` to the
 * world), so the test needs a subclass to reach them, exactly as a consumer would.
 */
final class UserRepository extends Repository
{
    /**
     * @return list<UserRow>
     */
    public function all(): array
    {
        return $this->fetchAll(
            SqlStatement::literal('SELECT id, name, age FROM users ORDER BY id'),
            UserRow::class,
        );
    }

    public function byId(int $id): ?UserRow
    {
        return $this->fetchOne(
            SqlStatement::literal('SELECT id, name, age FROM users WHERE id = ?', [$id]),
            UserRow::class,
        );
    }

    /**
     * Projects a column `UserRow` does not declare, to show strict hydration refusing it.
     *
     * @return list<UserRow>
     */
    public function allWithAnUndeclaredColumn(): array
    {
        return $this->fetchAll(
            SqlStatement::literal('SELECT id, name, age, secret FROM users'),
            UserRow::class,
        );
    }

    /**
     * @return list<UserRow>
     */
    public function fromABrokenStatement(): array
    {
        return $this->fetchAll(
            SqlStatement::literal('SELECT * FROM a_table_that_does_not_exist'),
            UserRow::class,
        );
    }

    public function rename(int $id, string $name): int
    {
        return $this->execute(
            SqlStatement::literal('UPDATE users SET name = ? WHERE id = ?', [$name, $id]),
        );
    }

    public function insert(int $id, string $name, ?int $age): int
    {
        return $this->execute(
            SqlStatement::literal('INSERT INTO users (id, name, age) VALUES (?, ?, ?)', [$id, $name, $age]),
        );
    }

    /**
     * @template T
     *
     * @param callable(self): T $work
     *
     * @return T
     */
    public function inTransaction(callable $work): mixed
    {
        /** @var callable(Repository): T $work */
        return $this->withTransaction($work);
    }
}
