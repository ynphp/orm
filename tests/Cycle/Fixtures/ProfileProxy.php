<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
declare(strict_types=1);

namespace Spiral\Cycle\Tests\Fixtures;

use Spiral\Cycle\ORMInterface;
use Spiral\Cycle\Promise\PromiseInterface;
use Spiral\Cycle\Select;

class ProfileProxy extends Profile implements PromiseInterface
{
    /** @var ORMInterface|null @internal */
    private $__orm;

    /** @var string|null */
    private $__target;

    /** @var array */
    private $__scope;

    /**
     * @param ORMInterface $orm
     * @param string       $target
     * @param array        $scope
     */
    public function __construct(ORMInterface $orm, string $target, array $scope)
    {
        $this->__orm = $orm;
        $this->__target = $target;
        $this->__scope = $scope;
    }

    /**
     * @inheritdoc
     */
    public function __loaded(): bool
    {
        return empty($this->__orm);
    }

    /**
     * @inheritdoc
     */
    public function __role(): string
    {
        return $this->__target;
    }

    /**
     * @inheritdoc
     */
    public function __scope(): array
    {
        return $this->__scope;
    }

    /**
     * @inheritdoc
     */
    public function __resolve()
    {
        if (!is_null($this->__orm)) {
            $select = new Select($this->__orm, $this->__target);
            $data = $select->constrain(
                $this->__orm->getSource($this->__target)->getConstrain()
            )->where($this->__scope)->fetchData();

            $this->__orm->getMapper($this->__target)->hydrate($this, $data[0]);

            $this->__orm = null;
        }

        return $this;
    }

    public function getID()
    {
        $this->__resolve();

        return parent::getID();
    }

    public function getImage()
    {
        $this->__resolve();

        return parent::getImage();
    }
}