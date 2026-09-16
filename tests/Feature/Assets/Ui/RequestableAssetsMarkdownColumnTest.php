<?php

namespace Tests\Feature\Assets\Ui;

use App\Models\Asset;
use App\Models\CustomField;
use App\Models\User;
use Tests\TestCase;

/**
 * Issue #19479 follow-up. Since the transformer now emits markdown-textarea
 * values verbatim (see AssetCustomFieldMarkdownTest), any DOM the API
 * response feeds into must escape it itself. account/requestable-assets.blade.php
 * builds its own <thead> for the assets tab directly, independent of
 * AssetPresenter::dataTableLayoutRequestable() - so patching that presenter
 * method alone leaves this page's markdown column unescaped and exploitable.
 *
 * This pins the column header contract: a markdown-textarea field must
 * carry data-formatter="plainTextFormatter", every other element type must
 * not (it would double-escape values the transformer already e()'d).
 */
class RequestableAssetsMarkdownColumnTest extends TestCase
{
    public function test_markdown_custom_field_column_carries_plain_text_formatter(): void
    {
        $this->markIncompleteIfMySQL('Custom Fields tests do not work on MySQL');

        // The assets tab only renders when at least one requestable asset exists.
        Asset::factory()->requestable()->create();

        $markdownField = CustomField::factory()->testMarkdownTextarea()->create([
            'name' => 'Markdown Column '.uniqid(),
            'show_in_requestable_list' => '1',
        ]);
        $markdownField = CustomField::where('id', $markdownField->id)->firstOrFail();

        $textareaField = CustomField::factory()->create([
            'name' => 'Textarea Column '.uniqid(),
            'element' => 'textarea',
            'show_in_requestable_list' => '1',
        ]);
        $textareaField = CustomField::where('id', $textareaField->id)->firstOrFail();

        $response = $this->actingAs(User::factory()->viewRequestableAssets()->create())
            ->get(route('account.requestable'))
            ->assertOk();

        $response->assertSee(
            'data-field="custom_fields.'.$markdownField->db_column.'" data-sortable="true" data-formatter="plainTextFormatter"',
            false
        );
        $response->assertSee(
            'data-field="custom_fields.'.$textareaField->db_column.'" data-sortable="true"',
            false
        );
        $response->assertDontSee(
            'data-field="custom_fields.'.$textareaField->db_column.'" data-sortable="true" data-formatter="plainTextFormatter"',
            false
        );
    }
}
