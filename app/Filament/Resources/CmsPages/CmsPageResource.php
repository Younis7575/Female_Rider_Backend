<?php

namespace App\Filament\Resources\CmsPages;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\AuditLog;
use App\Models\CmsPage;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CmsPageResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = CmsPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Pages & policies';

    protected static string $viewPermission = 'cms.manage';

    protected static ?string $managePermission = 'cms.manage';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextInput::make('title')->required(),
                TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true),
                TextInput::make('version')->required()->default('1.0')->helperText('Bump the version when policy text changes materially.'),
                RichEditor::make('body')->required()->columnSpanFull(),
                Toggle::make('is_published')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->weight('bold')->searchable(),
            TextColumn::make('slug')->fontFamily('mono')->url(fn ($record) => url('/'.$record->slug), true),
            TextColumn::make('version')->badge(),
            IconColumn::make('is_published')->boolean(),
            TextColumn::make('updated_at')->since(),
        ])->recordActions([EditAction::make()->after(fn ($record) => AuditLog::record('cms.updated', $record, [], ['version' => $record->version]))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCmsPages::route('/'), 'create' => Pages\CreateCmsPage::route('/create'), 'edit' => Pages\EditCmsPage::route('/{record}/edit')];
    }
}
