<?php

namespace DoubleThreeDigital\Runway\Http\Controllers;

use Carbon\CarbonInterface;
use DoubleThreeDigital\Runway\Http\Requests\CreateRequest;
use DoubleThreeDigital\Runway\Http\Requests\EditRequest;
use DoubleThreeDigital\Runway\Http\Requests\IndexRequest;
use DoubleThreeDigital\Runway\Http\Requests\StoreRequest;
use DoubleThreeDigital\Runway\Http\Requests\UpdateRequest;
use DoubleThreeDigital\Runway\Runway;
use DoubleThreeDigital\Runway\Support\Json;
use Statamic\CP\Breadcrumbs;
use Statamic\Facades\Scope;
use Statamic\Fields\Field;
use Statamic\Http\Controllers\CP\CpController;

class ResourceController extends CpController
{
    use Traits\HasListingColumns;

    public function index(IndexRequest $request, $resourceHandle)
    {
        $resource = Runway::findResource($resourceHandle);
        $blueprint = $resource->blueprint();

        $listingConfig = [
            'preferencesPrefix' => "runway.{$resource->handle()}",
            'requestUrl'        => cp_route('runway.listing-api', ['resourceHandle' => $resource->handle()]),
            'listingUrl'        => cp_route('runway.index', ['resourceHandle' => $resource->handle()]),
        ];

        return view('runway::index', [
            'title'         => $resource->name(),
            'resource'      => $resource,
            'recordCount'   => $resource->model()->count(),
            'columns'       => $this->buildColumns($resource, $blueprint),
            'filters'       => Scope::filters("runway_{$resourceHandle}"),
            'listingConfig' => $listingConfig,
            'actionUrl'     => cp_route('runway.actions.run', ['resourceHandle' => $resourceHandle]),
        ]);
    }

    public function create(CreateRequest $request, $resourceHandle)
    {
        $resource = Runway::findResource($resourceHandle);

        $blueprint = $resource->blueprint();
        $fields = $blueprint->fields();
        $fields = $fields->preProcess();

        $viewData = [
            'title' => __('Create :resource', [
                'resource' => $resource->singular(),
            ]),
            'action' => cp_route('runway.store', ['resourceHandle' => $resource->handle()]),
            'method' => 'POST',
            'breadcrumbs' => new Breadcrumbs([
                [
                    'text' => $resource->plural(),
                    'url' => cp_route('runway.index', [
                        'resourceHandle' => $resource->handle(),
                    ]),
                ],
            ]),
            'resource' => $request->wantsJson()
                ? $resource->toArray()
                : $resource,
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
            'permalink' => null,
            'resourceHasRoutes' => $resource->hasRouting(),
        ];

        if ($request->wantsJson()) {
            return $viewData;
        }

        return view('runway::create', $viewData);
    }

    public function store(StoreRequest $request, $resourceHandle)
    {
        $postCreatedHooks = [];

        $resource = Runway::findResource($resourceHandle);
        $record = $resource->model();

        foreach ($resource->blueprint()->fields()->all() as $fieldKey => $field) {
            $processedValue = $field->fieldtype()->process($request->get($fieldKey));

            // Skip section fields as there's nothing to store.
            if ($field->type() === 'section') {
                continue;
            }

            // Store the HasMany field's value in the $postCreatedHooks array so we
            // can process it after we've finished creating this model.
            if ($field->type() === 'has_many') {
                if ($processedValue) {
                    $postCreatedHooks[] = $processedValue;
                }

                continue;
            }

            // If it's a BelongsTo field & the $processedValue is an array, then we
            // want the first item in the array.
            if ($field->type() === 'belongs_to' && is_array($processedValue)) {
                $processedValue = $processedValue[0];
            }

            // If the $processedValue is an array & no cast is set on the model then
            // let's JSON encode it.
            if (
                is_array($processedValue)
                && ! $record->hasCast($fieldKey, ['json', 'array', 'collection', 'object', 'encrypted:array', 'encrypted:collection', 'encrypted:object'])
            ) {
                $processedValue = json_encode($processedValue, JSON_THROW_ON_ERROR);
            }

            $record->{$fieldKey} = $processedValue;
        }

        $record->save();

        // Runs anything in the $postCreatedHooks array. See HasManyFieldtype@process for an example
        // of where this is used.
        foreach ($postCreatedHooks as $postCreatedHook) {
            $postCreatedHook($resource, $record);
        }

        return [
            'data' => $this->getReturnData($resource, $record),
            'redirect' => cp_route('runway.edit', [
                'resourceHandle'  => $resource->handle(),
                'record' => $record->{$resource->routeKey()},
            ]),
        ];
    }

    public function edit(EditRequest $request, $resourceHandle, $record)
    {
        $resource = Runway::findResource($resourceHandle);
        $record = $resource->model()->where($resource->routeKey(), $record)->first();

        $values = [];
        $blueprintFieldKeys = $resource->blueprint()->fields()->all()->keys()->toArray();

        foreach ($blueprintFieldKeys as $fieldKey) {
            $value = $record->{$fieldKey};

            // When $value is a Carbon instance, format it with the format
            // specified in the blueprint.
            if ($value instanceof CarbonInterface) {
                $format = $defaultFormat = 'Y-m-d H:i';

                if ($field = $resource->blueprint()->field($fieldKey)) {
                    $format = $field->get('format', $defaultFormat);
                }

                $value = $value->format($format);
            }

            // When $value is a JSON string, decode it.
            if (Json::isJson($value)) {
                $value = json_decode((string) $value, true);
            }

            $values[$fieldKey] = $value;
        }

        $blueprint = $resource->blueprint();
        $fields = $blueprint->fields()->addValues($values)->preProcess();

        $viewData = [
            'title' => __('Edit :resource', [
                'resource' => $resource->singular(),
            ]),
            'action' => cp_route('runway.update', [
                'resourceHandle'  => $resource->handle(),
                'record' => $record->{$resource->routeKey()},
            ]),
            'method' => 'PATCH',
            'breadcrumbs' => new Breadcrumbs([
                [
                    'text' => $resource->plural(),
                    'url' => cp_route('runway.index', [
                        'resourceHandle' => $resource->handle(),
                    ]),
                ],
            ]),
            'resource' => $resource,
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
            'permalink' => $resource->hasRouting()
                ? $record->uri()
                : null,
            'resourceHasRoutes' => $resource->hasRouting(),
            'currentRecord' => [
                'id'    => $record->getKey(),
                'title' => $record->{$resource->titleField()},
                'edit_url' => $request->url(),
            ],
        ];

        if ($request->wantsJson()) {
            return $viewData;
        }

        return view('runway::edit', $viewData);
    }

    public function update(UpdateRequest $request, $resourceHandle, $record)
    {
        $resource = Runway::findResource($resourceHandle);
        $record = $resource->model()->where($resource->routeKey(), $record)->first();

        foreach ($resource->blueprint()->fields()->all() as $fieldKey => $field) {
            $processedValue = $field->fieldtype()->process($request->get($fieldKey));

            // Skip section & HasMany fields as there's nothing to store.
            if ($field->type() === 'section' || $field->type() === 'has_many') {
                continue;
            }

            // If it's a BelongsTo field & the $processedValue is an array, then we
            // want the first item in the array.
            if ($field->type() === 'belongs_to' && is_array($processedValue)) {
                $processedValue = $processedValue[0];
            }

            // If the $processedValue is an array & no cast is set on the model then
            // let's JSON encode it.
            if (
                is_array($processedValue)
                && ! $record->hasCast($fieldKey, ['json', 'array', 'collection', 'object', 'encrypted:array', 'encrypted:collection', 'encrypted:object'])
            ) {
                $processedValue = json_encode($processedValue, JSON_THROW_ON_ERROR);
            }

            $record->{$fieldKey} = $processedValue;
        }

        $record->save();

        // In the case of the 'Relationship' fields in Table Mode, when a model is updated
        // in the stack, we also need to return it's relations.
        if ($request->get('from_inline_publish_form')) {
            collect($resource->blueprint()->fields()->all())
                ->filter(function (Field $field) {
                    return $field->type() === 'belongs_to'
                        || $field->type() === 'has_many';
                })
                ->each(function (Field $field) use (&$record, $resource) {
                    $relatedResource = Runway::findResource($field->get('resource'));

                    $column = $relatedResource->titleField();

                    $relationshipName = $resource->eagerLoadingRelations()->get($field->handle()) ?? $field->handle();

                    $record->{$field->handle()} = $record->{$relationshipName}()
                        ->select('id', $column)
                        ->get()
                        ->each(function ($model) use ($relatedResource, $column) {
                            $model->title = $model->{$column};

                            $model->edit_url = cp_route('runway.edit', [
                                'resourceHandle' => $relatedResource->handle(),
                                'record' => $model->{$relatedResource->routeKey()},
                            ]);

                            return $model;
                        });
                });
        }

        return [
            'data' => $this->getReturnData($resource, $record),
        ];
    }

    /**
     * Build an array with the correct return data for the inline publish forms.
     */
    protected function getReturnData($resource, $record)
    {
        return array_merge($record->toArray(), [
            'title' => $record->{$resource->titleField()},
            'edit_url' => cp_route('runway.edit', [
                'resourceHandle'  => $resource->handle(),
                'record' => $record->{$resource->routeKey()},
            ]),
        ]);
    }
}
