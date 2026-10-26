<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog of customer tags of each Workspace («Etiquetas» of Clientes).
 *
 *  - `customer_tag_definitions`: the valid tags of a Workspace. `normalized_name` (Unicode NFC, single spaces, lower case; accents are KEPT) is unique per Workspace,
 *    so «VIP», «vip » and the same word typed with a decomposed accent are one tag, while «vía» and «via» stay two (they may mean different things; the screen lists
 *    them as possible duplicates for a person to review). `is_active` = false stops a tag from being offered to new assignments; nothing else changes.
 *  - `customer_tags.tag_id`: points to the definition (composite key with the Workspace, so the database refuses a tag of another Workspace). `customer_tags.tag`
 *    stays as a copy of the definition's name (read by the filters, the campaigns and the demo data) and is updated when a tag is renamed.
 *
 * Data: one definition is created per Workspace and distinct normalized text already in `customer_tags` (name = the normalized text, lower case as customer tags always were) and every existing row is
 * linked to it. No customer loses or gains a tag and no row is deleted or merged: two rows of one customer that normalize to the same tag simply point to the same
 * definition. Reversible: down() removes the link and the table; the `tag` text of every row is untouched, so the previous behavior returns exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_tag_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('normalized_name', 40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'normalized_name']);
        });

        Schema::table('customer_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('tag_id')->nullable()->after('customer_id');
            $table->foreign(['tag_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('customer_tag_definitions')->restrictOnDelete();
        });

        $definitions = [];

        DB::table('customer_tags')->orderBy('id')->select(['id', 'workspace_id', 'tag'])->each(function (object $row) use (&$definitions) {
            $name = self::clean($row->tag);
            $key = $row->workspace_id.'|'.mb_strtolower($name);

            $definitions[$key] ??= DB::table('customer_tag_definitions')->insertGetId([
                'workspace_id' => $row->workspace_id, 'name' => mb_substr(mb_strtolower($name), 0, 40), 'normalized_name' => mb_substr(mb_strtolower($name), 0, 40),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('customer_tags')->where('id', $row->id)->update(['tag_id' => $definitions[$key]]);
        });
    }

    public function down(): void
    {
        Schema::table('customer_tags', function (Blueprint $table) {
            $table->dropForeign(['tag_id', 'workspace_id']);
            $table->dropColumn('tag_id');
        });

        Schema::dropIfExists('customer_tag_definitions');
    }

    private static function clean(string $name): string
    {
        $normalized = class_exists(Normalizer::class) ? (Normalizer::normalize($name, Normalizer::FORM_C) ?: $name) : $name;

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }
};
