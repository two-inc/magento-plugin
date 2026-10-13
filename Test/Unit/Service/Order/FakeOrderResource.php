<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Sales\Model\ResourceModel\Order as OrderResource;

/**
 * The sales connection as StatusFulfilment sees it: a transaction level, and
 * commit callbacks that run on the outermost commit and are dropped on a
 * rollback, as core's ExecuteCommitCallbacks plugin on the adapter does.
 * Rows written through write() persist only when the outermost transaction
 * commits; any rollback discards them, as a nested rollback in MySQL dooms
 * the whole transaction.
 */
class FakeOrderResource extends OrderResource
{
    /** @var int */
    public $level = 0;

    /** @var callable[] */
    public $callbacks = [];

    /** @var array rows committed */
    public $rows = [];

    /**
     * @var \Throwable|null what the outermost rollback throws, as when the connection is lost. Core's
     * Mysql::rollBack() fails in PDO before it decrements, so the level stays at 1, the commit callbacks
     * stay (ExecuteCommitCallbacks::afterRollBack never runs) and every later write fails.
     */
    public $outermostRollBackFails;

    /** @var bool whether the connection is gone */
    private $lost = false;

    /** @var array rows written inside the open transaction */
    private $pending = [];

    /**
     * @param mixed $row
     */
    public function write($row): void
    {
        if ($this->lost) {
            throw new \RuntimeException('MySQL server has gone away');
        }
        if ($this->level === 0) {
            $this->rows[] = $row;
            return;
        }
        $this->pending[] = $row;
    }

    /**
     * Reads the row into the object; the test order stands in for the read.
     */
    public function load($object, $value): self
    {
        $object->load($value);
        return $this;
    }

    public function getConnection(): self
    {
        return $this;
    }

    public function getTransactionLevel(): int
    {
        return $this->level;
    }

    public function addCommitCallback($callback): self
    {
        $this->callbacks[] = $callback;
        return $this;
    }

    public function beginTransaction(): self
    {
        $this->level++;
        return $this;
    }

    public function commit(): self
    {
        $this->level--;
        if ($this->level === 0) {
            array_push($this->rows, ...$this->pending);
            $this->pending = [];
            $callbacks = $this->callbacks;
            $this->callbacks = [];
            foreach ($callbacks as $callback) {
                $callback();
            }
        }
        return $this;
    }

    public function rollBack(): self
    {
        if ($this->level === 1 && $this->outermostRollBackFails !== null) {
            $this->lost = true;
            throw $this->outermostRollBackFails;
        }
        $this->level--;
        $this->callbacks = [];
        $this->pending = [];
        return $this;
    }
}
