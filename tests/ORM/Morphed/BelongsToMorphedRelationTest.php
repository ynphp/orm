<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\ORM\Tests\Morphed;

use Spiral\ORM\Entity\Mapper;
use Spiral\ORM\Heap;
use Spiral\ORM\PromiseInterface;
use Spiral\ORM\Relation;
use Spiral\ORM\Schema;
use Spiral\ORM\Tests\BaseTest;
use Spiral\ORM\Tests\Fixtures\Image;
use Spiral\ORM\Tests\Fixtures\ImagedInterface;
use Spiral\ORM\Tests\Fixtures\Post;
use Spiral\ORM\Tests\Fixtures\User;
use Spiral\ORM\Tests\Traits\TableTrait;
use Spiral\ORM\Transaction;

// Belongs to morphed relation does not support eager loader, this relation can only work using lazy loading
// and promises.
abstract class BelongsToMorphedRelationTest extends BaseTest
{
    use TableTrait;

    public function setUp()
    {
        parent::setUp();

        $this->makeTable('user', [
            'id'      => 'primary',
            'email'   => 'string',
            'balance' => 'float'
        ]);

        $this->getDatabase()->table('user')->insertMultiple(
            ['email', 'balance'],
            [
                ['hello@world.com', 100],
                ['another@world.com', 200],
            ]
        );

        $this->makeTable('post', [
            'id'      => 'primary',
            'user_id' => 'integer,nullable',
            'title'   => 'string',
            'content' => 'string'
        ]);

        $this->getDatabase()->table('post')->insertMultiple(
            ['title', 'user_id', 'content'],
            [
                ['post 1', 1, 'post 1 body'],
                ['post 2', 1, 'post 2 body'],
                ['post 3', 2, 'post 3 body'],
                ['post 4', 2, 'post 4 body'],
            ]
        );

        $this->makeTable('image', [
            'id'          => 'primary',
            'parent_id'   => 'integer,nullable',
            'parent_type' => 'string,nullable',
            'url'         => 'string'
        ]);

        $this->getDatabase()->table('image')->insertMultiple(
            ['parent_id', 'parent_type', 'url'],
            [
                [1, 'user', 'user-image.png'],
                [1, 'post', 'post-image.png'],
                [2, 'user', 'user-2-image.png'],
                [2, 'post', 'post-2-image.png'],
                [3, 'post', 'post-3-image.png'],
            ]
        );

        $this->orm = $this->orm->withSchema(new Schema([
            User::class  => [
                Schema::ALIAS       => 'user',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'email', 'balance'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'image' => [
                        Relation::TYPE   => Relation::MORPHED_HAS_ONE,
                        Relation::TARGET => Image::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::INNER_KEY => 'id',
                            Relation::OUTER_KEY => 'parent_id',
                            Relation::MORPH_KEY => 'parent_type'
                        ],
                    ],
                    'posts' => [
                        Relation::TYPE   => Relation::HAS_MANY,
                        Relation::TARGET => Post::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::INNER_KEY => 'id',
                            Relation::OUTER_KEY => 'user_id'
                        ]
                    ]
                ]
            ],
            Post::class  => [
                Schema::ALIAS       => 'post',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'post',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'title', 'content'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'image' => [
                        Relation::TYPE   => Relation::MORPHED_HAS_ONE,
                        Relation::TARGET => Image::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::INNER_KEY => 'id',
                            Relation::OUTER_KEY => 'parent_id',
                            Relation::MORPH_KEY => 'parent_type'
                        ],
                    ]
                ]
            ],
            Image::class => [
                Schema::ALIAS       => 'image',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'image',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'parent_id', 'parent_type', 'url'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'parent' => [
                        Relation::TYPE   => Relation::BELONGS_TO_MORPHED,
                        Relation::TARGET => ImagedInterface::class,
                        Relation::SCHEMA => [
                            Relation::NULLABLE  => true,
                            Relation::CASCADE   => true,
                            Relation::OUTER_KEY => 'id',
                            Relation::INNER_KEY => 'parent_id',
                            Relation::MORPH_KEY => 'parent_type'
                        ],
                    ]
                ]
            ],
        ]));
    }

    public function testGetParent()
    {
        $c = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $this->assertInstanceOf(PromiseInterface::class, $c->parent);

        $this->captureReadQueries();
        $this->assertInstanceOf(User::class, $c->parent->__resolve());
        $this->assertSame('hello@world.com', $c->parent->__resolve()->email);
        $this->assertNumReads(1);
    }

    public function testNoWritesNotLoaded()
    {
        $c = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $this->assertInstanceOf(PromiseInterface::class, $c->parent);

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c);
        $tr->run();
        $this->assertNumWrites(0);
    }

    public function testGetParentLoaded()
    {
        $u = $this->orm->getMapper(User::class)->getRepository()->findByPK(1);

        $c = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);

        $this->assertInstanceOf(User::class, $c->parent);
        $this->assertSame('hello@world.com', $c->parent->email);
    }

    public function testNoWritesLoaded()
    {
        $c = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $this->assertInstanceOf(PromiseInterface::class, $c->parent);

        $this->assertInstanceOf(User::class, $c->parent->__resolve());

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c);
        $tr->run();
        $this->assertNumWrites(0);
    }

    public function testGetParentPostloaded()
    {
        $c = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $this->assertInstanceOf(PromiseInterface::class, $c->parent);

        $u = $this->orm->getMapper(User::class)->getRepository()->findByPK(1);

        $this->captureReadQueries();
        $this->assertInstanceOf(User::class, $c->parent->__resolve());
        $this->assertSame('hello@world.com', $c->parent->__resolve()->email);
        $this->assertNumReads(0);
    }

    public function testCreateWithMorphedExistedParent()
    {
        $c = new Image();
        $c->url = 'test.png';

        $c->parent = $this->orm->getMapper(User::class)->getRepository()->findByPK(1);

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c);
        $tr->run();
        $this->assertNumWrites(1);

        // consecutive
        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c);
        $tr->run();
        $this->assertNumWrites(0);

        $this->orm = $this->orm->withHeap(new Heap());

        $c = $this->orm->getMapper(Image::class)->getRepository()->findByPK(6);
        $this->assertInstanceOf(PromiseInterface::class, $c->parent);

        $this->captureReadQueries();
        $this->assertInstanceOf(User::class, $c->parent->__resolve());
        $this->assertSame('hello@world.com', $c->parent->__resolve()->email);
        $this->assertNumReads(1);
    }

    public function testCreateWithNewParent()
    {
        $c = new Image();
        $c->url = 'test.png';

        $c->parent = new Post();
        $c->parent->title = "post title";
        $c->parent->content = "post content";

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c);
        $tr->run();
        $this->assertNumWrites(2);

        // consecutive
        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c);
        $tr->run();
        $this->assertNumWrites(0);

        $this->orm = $this->orm->withHeap(new Heap());

        $c = $this->orm->getMapper(Image::class)->getRepository()->findByPK(6);
        $this->assertInstanceOf(PromiseInterface::class, $c->parent);

        $this->captureReadQueries();
        $this->assertInstanceOf(Post::class, $c->parent->__resolve());
        $this->assertSame('post title', $c->parent->__resolve()->title);
        $this->assertNumReads(1);
    }

    public function testSetParentAndUpdateParent()
    {
        $c = new Image();
        $c->url = 'test.png';

        $c->parent = $this->orm->getMapper(User::class)->getRepository()->findByPK(1);
        $c->parent->balance = 777;

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c);
        $tr->run();
        $this->assertNumWrites(2);

        // consecutive
        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c);
        $tr->run();
        $this->assertNumWrites(0);

        $this->orm = $this->orm->withHeap(new Heap());

        $c = $this->orm->getMapper(Image::class)->getRepository()->findByPK(6);
        $this->assertInstanceOf(PromiseInterface::class, $c->parent);

        $this->captureReadQueries();
        $this->assertInstanceOf(User::class, $c->parent->__resolve());
        $this->assertSame('hello@world.com', $c->parent->__resolve()->email);
        $this->assertEquals(777, $c->parent->__resolve()->balance);
        $this->assertNumReads(1);
    }

    public function testChangeParentWithLoading()
    {
        $c1 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $c2 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(2);

        $this->assertInstanceOf(User::class, $c1->parent->__resolve());
        $this->assertInstanceOf(Post::class, $c2->parent->__resolve());

        list($c1->parent, $c2->parent) = [$c2->parent, $c1->parent];

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c1);
        $tr->store($c2);
        $tr->run();
        $this->assertNumWrites(2);

        $this->orm = $this->orm->withHeap(new Heap());
        $c1 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $c2 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(2);

        $this->assertInstanceOf(Post::class, $c1->parent->__resolve());
        $this->assertInstanceOf(User::class, $c2->parent->__resolve());
    }

    public function testChangeParentWithoutLoading()
    {
        $c1 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $c2 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(2);

        list($c1->parent, $c2->parent) = [$c2->parent, $c1->parent];

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c1);
        $tr->store($c2);
        $tr->run();
        $this->assertNumWrites(2);

        $this->orm = $this->orm->withHeap(new Heap());
        $c1 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $c2 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(2);

        $this->assertInstanceOf(Post::class, $c1->parent->__resolve());
        $this->assertInstanceOf(User::class, $c2->parent->__resolve());
    }

    public function testChangeParentLoadedAfter()
    {
        $c1 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $c2 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(2);

        $u = $this->orm->getMapper(User::class)->getRepository()->findByPK(1);
        $u = $this->orm->getMapper(Post::class)->getRepository()->findByPK(1);

        list($c1->parent, $c2->parent) = [$c2->parent, $c1->parent];

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c1);
        $tr->store($c2);
        $tr->run();
        $this->assertNumWrites(2);

        $this->orm = $this->orm->withHeap(new Heap());
        $c1 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $c2 = $this->orm->getMapper(Image::class)->getRepository()->findByPK(2);

        $this->assertInstanceOf(Post::class, $c1->parent->__resolve());
        $this->assertInstanceOf(User::class, $c2->parent->__resolve());
    }

    public function testSetNull()
    {
        $c = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $c->parent = null;

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->store($c);
        $tr->run();
        $this->assertNumWrites(1);

        $this->orm = $this->orm->withHeap(new Heap());
        $c = $this->orm->getMapper(Image::class)->getRepository()->findByPK(1);
        $this->assertNull($c->parent);
    }
}