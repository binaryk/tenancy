<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Stancl\Tenancy\Tests\Etc\Tenant;

class SingleDatabaseTenancyTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        BelongsToTenant::$tenantIdColumn = 'tenant_id';

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('text');

            $table->string('tenant_id');

            $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('text');

            $table->string('post_id');

            $table->foreign('post_id')->references('id')->on('posts')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /** @test */
    public function primary_models_are_scoped_to_the_current_tenant()
    {
        // acme context
        tenancy()->initialize($acme = Tenant::create([
            'id' => 'acme',
        ]));

        $post = Post::create(['text' => 'Foo']);

        $this->assertSame('acme', $post->tenant_id);
        $this->assertSame('acme', $post->tenant->id);

        $post = Post::first();

        $this->assertSame('acme', $post->tenant_id);
        $this->assertSame('acme', $post->tenant->id);

        // ======================================
        // foobar context
        tenancy()->initialize($foobar = Tenant::create([
            'id' => 'foobar',
        ]));

        $post = Post::create(['text' => 'Bar']);

        $this->assertSame('foobar', $post->tenant_id);
        $this->assertSame('foobar', $post->tenant->id);

        $post = Post::first();

        $this->assertSame('foobar', $post->tenant_id);
        $this->assertSame('foobar', $post->tenant->id);

        // ======================================
        // acme context again

        tenancy()->initialize($acme);

        $post = Post::first();
        $this->assertSame('acme', $post->tenant_id);
        $this->assertSame('acme', $post->tenant->id);

        // Assert foobar models are inaccessible in acme context
        $this->assertSame(1, Post::count());
    }

    /** @test */
    public function primary_models_are_not_scoped_in_the_central_context()
    {
        $this->primary_models_are_scoped_to_the_current_tenant();

        tenancy()->end();

        $this->assertSame(2, Post::count());
    }

    /** @test */
    public function secondary_models_are_scoped_to_the_current_tenant_when_accessed_via_primary_model()
    {
        // acme context
        tenancy()->initialize($acme = Tenant::create([
            'id' => 'acme',
        ]));

        $post = Post::create(['text' => 'Foo']);
        $post->comments()->create(['text' => 'Comment text']);

        // ================
        // foobar context
        tenancy()->initialize($foobar = Tenant::create([
            'id' => 'foobar',
        ]));

        $post = Post::create(['text' => 'Bar']);
        $post->comments()->create(['text' => 'Comment text 2']);

        // ================
        // acme context again
        tenancy()->initialize($acme);
        $this->assertSame(1, Post::count());
        $this->assertSame(1, Post::first()->comments->count());
    }

    /** @test */
    public function secondary_models_are_NOT_scoped_to_the_current_tenant_when_accessed_directly()
    {
        $this->secondary_models_are_scoped_to_the_current_tenant_when_accessed_via_primary_model();

        // We're in acme context
        $this->assertSame('acme', tenant('id'));

        // There is no way to scope this 🤷‍♂
        $this->assertSame(2, Comment::count());
    }

    /** @test */
    public function secondary_models_are_NOT_scoped_in_the_central_context()
    {
        $this->secondary_models_are_scoped_to_the_current_tenant_when_accessed_via_primary_model();

        tenancy()->end();

        $this->assertSame(2, Comment::count());
    }

    /** @test */
    public function global_models_are_not_scoped_at_all()
    {
        Schema::create('global_resource', function (Blueprint $table) {
            $table->increments('id');
            $table->string('text');
        });

        GlobalResource::create(['text' => 'First']);
        GlobalResource::create(['text' => 'Second']);

        $acme = Tenant::create([
            'id' => 'acme',
        ]);

        $acme->run(function () {
            $this->assertSame(2, GlobalResource::count());

            GlobalResource::create(['text' => 'Third']);
            GlobalResource::create(['text' => 'Fourth']);
        });

        $this->assertSame(4, GlobalResource::count());
    }

    /** @test */
    public function tenant_id_and_relationship_is_auto_added_when_creating_primary_resources_in_tenant_context()
    {
        tenancy()->initialize($acme = Tenant::create([
            'id' => 'acme',
        ]));

        $post = Post::create(['text' => 'Foo']);

        $this->assertSame('acme', $post->tenant_id);
        $this->assertTrue($post->relationLoaded('tenant'));
        $this->assertSame($acme, $post->tenant);
        $this->assertSame(tenant(), $post->tenant);
    }

    /** @test */
    public function tenant_id_is_not_auto_added_when_creating_primary_resources_in_central_context()
    {
        $this->expectException(QueryException::class);

        Post::create(['text' => 'Foo']);
    }

    /** @test */
    public function tenant_id_column_name_can_be_customized()
    {
        BelongsToTenant::$tenantIdColumn = 'team_id';

        Schema::drop('posts');
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('text');

            $table->string('team_id');

            $table->foreign('team_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
        });

        tenancy()->initialize($acme = Tenant::create([
            'id' => 'acme',
        ]));

        $post = Post::create(['text' => 'Foo']);

        $this->assertSame('acme', $post->team_id);

        // ======================================
        // foobar context
        tenancy()->initialize($foobar = Tenant::create([
            'id' => 'foobar',
        ]));

        $post = Post::create(['text' => 'Bar']);

        $this->assertSame('foobar', $post->team_id);

        $post = Post::first();

        $this->assertSame('foobar', $post->team_id);

        // ======================================
        // acme context again

        tenancy()->initialize($acme);

        $post = Post::first();
        $this->assertSame('acme', $post->team_id);

        // Assert foobar models are inaccessible in acme context
        $this->assertSame(1, Post::count());
    }
}

class Post extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
    public $timestamps = false;

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
}

class Comment extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}

class GlobalResource extends Model
{
    protected $guarded = [];
    public $timestamps = false;
}