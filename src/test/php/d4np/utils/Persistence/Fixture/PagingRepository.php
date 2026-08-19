<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Persistence\Fixture;

use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\Sort;
use D4np\Utils\Persistence\Page;
use D4np\Utils\Persistence\PageRequest;
use D4np\Utils\Persistence\Repository;

/**
 * A repository that reaches `Repository::fetchPage()` directly, with a query it builds itself.
 *
 * `TableGateway` always supplies an ordering, so the refusal of an unordered query is unreachable
 * through it — this fixture is the seam a real subclass uses when it needs a read the gateway does
 * not offer, and therefore the only place the requirement can be exercised from both sides.
 */
final class PagingRepository extends Repository
{
    /** @return Page<Person> */
    public function pageOfPeopleOrdered(PageRequest $request): Page
    {
        return $this->fetchPage($this->people()->orderBy('id', Sort::Asc), $request, Person::class);
    }

    /** @return Page<Person> */
    public function pageOfPeopleUnordered(PageRequest $request): Page
    {
        return $this->fetchPage($this->people(), $request, Person::class);
    }

    private function people(): QueryBuilder
    {
        return (new QueryBuilder($this->connection, 'people'))
            ->select('id', 'name', 'age', 'status');
    }
}
