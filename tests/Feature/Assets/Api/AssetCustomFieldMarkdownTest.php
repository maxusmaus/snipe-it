<?php

namespace Tests\Feature\Assets\Api;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Issue #19479. A markdown-textarea custom field is stored as markdown and must
 * come back out of the API as markdown, so consumers do not have to convert
 * rendered HTML back to markdown before they can use it.
 *
 * The value is emitted verbatim: not run through Helper::renderMarkdown(), and
 * not e()'d either, since escaping corrupts the markdown itself (a leading `>`
 * turns into `&gt;` and stops being a blockquote). Rendering and escaping both
 * live in the view layer now — see customFieldsFormatter / plainTextFormatter
 * in resources/views/partials/bootstrap-table.blade.php.
 *
 * Custom fields are expensive to create (the `created` hook runs an ALTER TABLE
 * on `assets`), so each test here builds the smallest fieldset it needs.
 */
class AssetCustomFieldMarkdownTest extends TestCase
{
    public function test_markdown_custom_field_value_is_returned_verbatim(): void
    {
        $this->markIncompleteIfMySQL('Custom Fields tests do not work on MySQL');

        // Deliberately awkward: a blockquote marker, a bare `>` and `&`, and
        // double quotes — all of which e() would mangle into entities.
        $markdown = "## Maintenance\n\n> Kosten > 100 & \"dringend\"\n\n**bold** and a [link](https://example.test)";
        $plainText = 'Kosten > 100 & "dringend"';

        $markdownField = $this->createField('testMarkdownTextarea');
        $textareaField = $this->createField(attributes: ['element' => 'textarea']);

        $asset = $this->assetWithFields([
            $markdownField->db_column => $markdown,
            $textareaField->db_column => $plainText,
        ], [$markdownField, $textareaField]);

        $response = $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.assets.show', $asset))
            ->assertOk()
            ->assertJsonPath('custom_fields.'.$markdownField->name.'.value', $markdown)
            // Every other element type keeps the e() it has always had, so the
            // markdown field is the only unescaped value in the payload.
            ->assertJsonPath('custom_fields.'.$textareaField->name.'.value', e($plainText));

        $this->assertStringNotContainsString('<strong>', $response->json('custom_fields.'.$markdownField->name.'.value'));
        $this->assertStringNotContainsString('<h2>', $response->json('custom_fields.'.$markdownField->name.'.value'));
    }

    /**
     * Verbatim means verbatim: a markdown field holding HTML comes back with
     * that HTML intact rather than escaped. This is the deliberate contract —
     * escaping is the view layer's job — so pin it down rather than leave it
     * looking like an oversight.
     */
    public function test_markdown_custom_field_html_is_neither_escaped_nor_stripped(): void
    {
        $this->markIncompleteIfMySQL('Custom Fields tests do not work on MySQL');

        $markdown = '<script>alert(1)</script>';

        $field = $this->createField('testMarkdownTextarea');
        $asset = $this->assetWithFields([$field->db_column => $markdown], [$field]);

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.assets.show', $asset))
            ->assertOk()
            ->assertJsonPath('custom_fields.'.$field->name.'.value', $markdown);
    }

    public function test_markdown_custom_field_value_is_returned_verbatim_in_requestable_list(): void
    {
        $this->markIncompleteIfMySQL('Custom Fields tests do not work on MySQL');

        $markdown = "**bold** and a [link](https://example.test)\n\n> quoted";

        $field = $this->createField('testMarkdownTextarea', ['show_in_requestable_list' => '1']);

        $asset = $this->assetWithFields(
            [$field->db_column => $markdown],
            [$field],
            fn ($factory) => $factory->requestable(),
        );

        $this->actingAsForApi(User::factory()->viewRequestableAssets()->create())
            ->getJson(route('api.assets.requestable'))
            ->assertOk()
            ->assertJsonPath('rows.0.id', $asset->id)
            ->assertJsonPath('rows.0.custom_fields.'.$field->db_column, $markdown);
    }

    public function test_encrypted_markdown_custom_field_is_verbatim_only_for_permitted_user(): void
    {
        $this->markIncompleteIfMySQL('Custom Fields tests do not work on MySQL');

        $markdown = "**secret** notes\n\n> classified";

        $field = $this->createField('testMarkdownTextarea', ['field_encrypted' => '1']);
        $asset = $this->assetWithFields([$field->db_column => Crypt::encrypt($markdown)], [$field]);

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.assets.show', $asset))
            ->assertOk()
            ->assertJsonPath('custom_fields.'.$field->name.'.value', $markdown);

        $this->actingAsForApi(User::factory()->viewAssets()->create())
            ->getJson(route('api.assets.show', $asset))
            ->assertOk()
            ->assertJsonPath(
                'custom_fields.'.$field->name.'.value',
                strtoupper(trans('admin/custom_fields/general.encrypted')),
            );
    }

    /**
     * Custom fields materialize their `db_column` in an afterCreating observer,
     * so the field is always re-read from the database rather than trusting the
     * in-memory instance the factory hands back.
     */
    private function createField(?string $state = null, array $attributes = []): CustomField
    {
        $uniqueName = 'Markdown Field '.uniqid();

        $factory = CustomField::factory();

        if ($state) {
            $factory = $factory->{$state}();
        }

        $factory->create(array_merge(['name' => $uniqueName], $attributes));

        return CustomField::where('name', $uniqueName)->firstOrFail();
    }

    /**
     * Build an asset whose model uses a fieldset containing the given fields,
     * with the given raw column values already stored.
     *
     * @param  array<string, string>  $columnValues
     * @param  array<int, CustomField>  $fields
     */
    private function assetWithFields(array $columnValues, array $fields, ?callable $factoryState = null): Asset
    {
        $fieldset = CustomFieldset::factory()->create();

        foreach ($fields as $order => $field) {
            $fieldset->fields()->attach($field, ['order' => $order + 1, 'required' => false]);
        }

        $model = AssetModel::factory()->create(['fieldset_id' => $fieldset->id]);

        $assetFactory = Asset::factory()->state(array_merge(['model_id' => $model->id], $columnValues));

        if ($factoryState) {
            $assetFactory = $factoryState($assetFactory);
        }

        return $assetFactory->create();
    }
}
