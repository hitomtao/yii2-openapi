<?php

/**
 * @copyright Copyright (c) 2018 Carsten Brandt <mail@cebe.cc> and contributors
 * @license https://github.com/cebe/yii2-openapi/blob/master/LICENSE
 */

namespace cebe\yii2openapi\generator;

use cebe\openapi\Reader;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Yii;
use yii\gii\CodeFile;
use yii\gii\Generator;
use yii\helpers\Html;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;

/**
 *
 *
 */
class ApiGenerator extends Generator
{
    public $openApiPath;
    public $ignoreSpecErrors = false;
    public $generateUrls = true;
    public $generateControllers = true;
    public $generateModels = true;
    /**
     * @var array List of model names to exclude
     */
    public $excludeModels = [];
    public $generateMigrations = true;


    public $urlConfigFile = '@app/config/urls.rest.php';


    /**
     * @return string name of the code generator
     */
    public function getName()
    {
        return 'REST API Generator';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription()
    {
        return 'This generator generates REST API code from an OpenAPI 3 specification.';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return array_merge(parent::rules(), [
            [['openApiPath', 'urlConfigFile'], 'filter', 'filter' => 'trim'],

            [['ignoreSpecErrors', 'generateUrls', 'generateModels', 'generateControllers'], 'boolean'],

            ['openApiPath', 'required'],
            ['openApiPath', 'validateSpec'],
            [['urlConfigFile'], 'required', 'when' => function (ApiGenerator $model) {
                return (bool) $model->generateUrls;
            }],


        ]);
    }

    public function validateSpec($attribute)
    {
        if ($this->ignoreSpecErrors) {
            return;
        }

        $openApi = $this->getOpenApiWithoutReferences();
        if (!$openApi->validate()) {
            $this->addError($attribute, 'Failed to validate OpenAPI spec:' . Html::ul($openApi->getErrors()));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'openApiPath' => 'OpenAPI 3 Spec file',
            'generateUrls' => 'Generate URL Rules',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function hints()
    {
        return array_merge(parent::hints(), [
            'openApiPath' => 'Path to the OpenAPI 3 Spec file. Type <code>@</code> to trigger autocomplete.',
            'urlConfigFile' => 'UrlRules will be written to this file.',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function autoCompleteData()
    {
        $vendor = Yii::getAlias('@vendor');
        $app = Yii::getAlias('@app');
        $runtime = Yii::getAlias('@runtime');
        $paths = [];
        $pathIterator = new RecursiveDirectoryIterator($app);
        $recursiveIterator = new RecursiveIteratorIterator($pathIterator);
        $files = new RegexIterator($recursiveIterator, '~.+\.(json|yaml|yml)$~i', RegexIterator::GET_MATCH);
        foreach ($files as $file) {
            if (strpos($file[0], $vendor) === 0) {
                $file = '@vendor' . substr($file[0], strlen($vendor));
                if (DIRECTORY_SEPARATOR === '\\') {
                    $file = str_replace('\\', '/', $file);
                }
            } elseif (strpos($file[0], $runtime) === 0) {
                $file = null;
            } elseif (strpos($file[0], $app) === 0) {
                $file = '@app' . substr($file[0], strlen($app));
                if (DIRECTORY_SEPARATOR === '\\') {
                    $file = str_replace('\\', '/', $file);
                }
            } else {
                $file = $file[0];
            }

            if ($file !== null) {
                $paths[] = $file;
            }
        }
        return [
            'openApiPath' => $paths,
//            'urlConfigFile' => [
//                '@app/config/urls.rest.php',
//            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function requiredTemplates()
    {
        $required = [];
        if ($this->generateUrls) {
            $required[] = 'urls.php';
        }
        if ($this->generateControllers) {
            $required[] = 'controller.php';
        }
        if ($this->generateModels) {
            $required[] = 'model.php';
        }
        if ($this->generateMigrations) {
            $required[] = 'migration.php';
        }
        return $required;
    }

    /**
     * {@inheritdoc}
     */
    public function stickyAttributes()
    {
        return array_merge(parent::stickyAttributes(), ['generateUrls', 'urlConfigFile']);
    }



    /**
     * @var OpenApi
     */
    private $_openApi;
    /**
     * @var OpenApi
     */
    private $_openApiWithoutRef;


    /**
     * @return OpenApi
     */
    protected function getOpenApi()
    {
        if ($this->_openApi === null) {
            $file = Yii::getAlias($this->openApiPath);
            if (StringHelper::endsWith($this->openApiPath, '.json', false)) {
                $this->_openApi = Reader::readFromJsonFile($file, OpenApi::class, false);
            } else {
                $this->_openApi = Reader::readFromYamlFile($file, OpenApi::class, false);
            }
        }
        return $this->_openApi;
    }

    protected function getOpenApiWithoutReferences()
    {
        if ($this->_openApiWithoutRef === null) {
            $file = Yii::getAlias($this->openApiPath);
            if (StringHelper::endsWith($this->openApiPath, '.json', false)) {
                $this->_openApiWithoutRef = Reader::readFromJsonFile($file, OpenApi::class, true);
            } else {
                $this->_openApiWithoutRef = Reader::readFromYamlFile($file, OpenApi::class, true);
            }
        }
        return $this->_openApiWithoutRef;
    }


    protected function generateUrls()
    {
        $openApi = $this->getOpenApiWithoutReferences();

        $urlRules = [];
        foreach ($openApi->paths as $path => $pathItem) {
            /** @var $pathItem PathItem */
            if ($path[0] !== '/') {
                throw new Exception('Path must begin with /');
            }

            $parts = explode('/', trim($path, '/'));

            $controller = [];
            $action = [];
            $params = false;
            $actionParams = [];
            foreach ($parts as $p => $part) {
                if (preg_match('/\{(.*)\}/', $part, $m)) {
                    $params = true;
                    $parts[$p] = '<' . $m[1] . '>';
                    if (isset($pathItem->parameters[$m[1]])) {
                        $actionParams[$m[1]] = $pathItem->parameters[$m[1]];
                    } else {
                        $actionParams[$m[1]] = null;
                    }
                    // TODO add regex to param based on openAPI type
                } elseif ($params) {
                    $action[] = $part;
                } else {
                    $controller[] = Inflector::singularize($part);
                }
            }
            $pattern = implode('/', $parts);

            $controller = implode('-', $controller);
            if (empty($controller)) {
                $controller = 'default';
            }
            $action = empty($action) ? '' : '-' . implode('-', $action);
            foreach ($pathItem->getOperations() as $method => $operation) {
                switch ($method) {
                    case 'get': $a = $params ? 'view' : 'index'; break;
                    case 'post': $a = 'create'; break;
                    case 'put': $a = 'update'; break;
                    case 'patch': $a = 'update'; break;
                    case 'delete': $a = 'delete'; break;
                    default: $a = "http-$method"; break;
                }
                $modelClass = null;
                if (isset($this->getOpenApi()->paths[$path]->getOperations()[$method])) {
                    $operationWithReference = $this->getOpenApi()->paths[$path]->getOperations()[$method];
                    $modelClass = $this->guessModelClass($operationWithReference, $a);
                }
                // fallback to known model class on same URL
                if ($modelClass === null && isset($this->_knownModelclasses[$path])) {
                    $modelClass = $this->_knownModelclasses[$path];
                } else {
                    $this->_knownModelclasses[$path] = $modelClass;
                }
                $urlRules[] = [
                    'path' => $path,
                    'method' => strtoupper($method),
                    'pattern' => $pattern,
                    'route' => "$controller/$a$action",
                    'actionParams' => $actionParams,
                    'openApiOperation' => $operation,
                    'modelClass' => $modelClass,
                ];
            }
            // TODO add options action
        }
        return $urlRules;
    }

    private $_knownModelclasses = [];

    private function guessModelClass(Operation $operationWithReference, $actionName)
    {
        switch ($actionName) {
            case 'create':
            case 'update':
            case 'delete':

                // first, check request body

                $requestBody = $operationWithReference->requestBody;
                if ($requestBody !== null) {
                    if ($requestBody instanceof Reference) {
                        $requestBody = $this->resolveReference($requestBody);
                    }
                    foreach ($requestBody->content as $contentType => $content) {
                        $modelClass = $this->guessModelClassFromContent($content);
                        if ($modelClass !== null) {
                            return $modelClass;
                        }
                    }
                }

                // no break, check response body if guess did not find model in request body
            case 'view':
            case 'index':

                // then, check response body

                if (!isset($operationWithReference->responses)) {
                    break;
                }
                foreach ($operationWithReference->responses as $code => $successResponse) {
                    if (((string) $code)[0] !== '2') {
                        continue;
                    }
                    if ($successResponse instanceof Reference) {
                        $successResponse = $this->resolveReference($successResponse);
                    }
                    foreach ($successResponse->content as $contentType => $content) {
                        $modelClass = $this->guessModelClassFromContent($content);
                        if ($modelClass !== null) {
                            return $modelClass;
                        }
                    }
                }

                break;
        }
    }

    private function guessModelClassFromContent(MediaType $content)
    {
        if (!($content->schema instanceof Reference)) {
            return null;
        }

        /** @var $referencedSchema Schema */
        $referencedSchema = $this->resolveReference($content->schema);
        if ($referencedSchema->type === 'array' && $referencedSchema->items instanceof Reference) {
            $ref = $referencedSchema->items->getReference();
        } elseif ($referencedSchema->type === null || $referencedSchema->type === 'object') {
            $ref = $content->schema->getReference();
        } else {
            return null;
        }
        if (strpos($ref, '#/components/schemas/') === 0) {
            return substr($ref, 21);
        }
        return null;
    }

    /**
     * @param Reference $reference
     * @return \cebe\openapi\SpecObjectInterface
     */
    private function resolveReference(Reference $reference)
    {
        return $reference->resolve(new ReferenceContext($this->getOpenApi(), Yii::getAlias($this->openApiPath)));
    }

    protected function generateControllers()
    {
        $urls = $this->generateUrls();

        $c = [];
        foreach ($urls as $url) {
            $parts = explode('/', $url['route'], 2);
            $c[$parts[0]][] = [
                'id' => $parts[1],
                'params' => array_keys($url['actionParams']),
                'modelClass' => $url['modelClass'],
            ];
        }
        return $c;
    }

    protected function generateModels()
    {
        $models = [];
        $resolvedOpenApi = $this->getOpenApiWithoutReferences();
        foreach ($this->getOpenApi()->components->schemas as $schemaName => $schema) {
            $attributes = [];
            $relations = [];
            if ((empty($schema->type) || $schema->type === 'object') && empty($schema->properties)) {
                continue;
            }
            if (!empty($schema->type) && $schema->type !== 'object') {
                continue;
            }
            if (in_array($schemaName, $this->excludeModels)) {
                continue;
            }

            foreach ($schema->properties as $name => $property) {
                if ($property instanceof Reference) {
                    $ref = $property->getReference();
                    $resolvedProperty = $resolvedOpenApi->components->schemas[$schemaName];
                    $dbName = "{$name}_id";
                    $dbType = 'integer'; // for a foreign key
                    if (strpos($ref, '#/components/schemas/') === 0) {
                        // relation
                        $type = substr($ref, 21);
                        $relations[$name] = [
                            'class' => $type,
                            'method' => 'hasOne',
                            'link' => ['id' => $dbName], // TODO pk may not be 'id'
                        ];
                    } else {
                        $type = $this->getSchemaType($resolvedProperty);
                    }
                } else {
                    $resolvedProperty = $property;
                    $type = $this->getSchemaType($property);
                    $dbName = $name;
                    $dbType = $this->getDbType($name, $property);
                }
                // relation
                if (is_array($type)) {
                    $relations[$name] = [
                        'class' => $type[1],
                        'method' => 'hasMany',
                        'link' => [Inflector::camel2id($schemaName, '_') . '_id' => 'id'], // TODO pk may not be 'id'
                    ];
                    $type = $type[0];
                }

                $attributes[] = [
                    'name' => $name,
                    'type' => $type,
                    'dbType' => $dbType,
                    'dbName' => $dbName,
                    'description' => $resolvedProperty->description,
                ];
            }

            $models[$schemaName] = [
                'name' => $schemaName,
                'tableName' => '{{%' . Inflector::camel2id(StringHelper::basename(Inflector::pluralize($schemaName)), '_') . '}}',
                'description' => $schema->description,
                'attributes' => $attributes,
                'relations' => $relations,
            ];
        }

        // TODO generate hasMany relations and inverse relations

        return $models;
    }

    /**
     * @param Schema $schema
     * @return string|array
     */
    protected function getSchemaType($schema)
    {
        switch ($schema->type) {
            case 'integer':
                return 'int';
            case 'boolean':
                return 'bool';
            case 'number': // can be double and float
                return 'float';
            case 'array':
                if (isset($schema->items) && $schema->items instanceof Reference) {
                    $ref = $schema->items->getReference();
                    if (strpos($ref, '#/components/schemas/') === 0) {
                        return [substr($ref, 21) . '[]', substr($ref, 21)];
                    }
                }
                // no break here
            default:
                return $schema->type;
        }
    }

    /**
     * @param string $name
     * @param Schema $schema
     * @return string
     */
    protected function getDbType($name, $schema)
    {
        if ($name === 'id') {
            return 'pk';
        }

        switch ($schema->type) {
            case 'string':
                if (isset($schema->maxLength)) {
                    return 'string(' . ((int) $schema->maxLength) . ')';
                }
                return 'text';
            case 'integer':
            case 'boolean':
                return $schema->type;
            case 'number': // can be double and float
                return $schema->format ?? 'float';
//            case 'array':
        // TODO array might refer to has_many relation
//                if (isset($schema->items) && $schema->items instanceof Reference) {
//                    $ref = $schema->items->getReference();
//                    if (strpos($ref, '#/components/schemas/') === 0) {
//                        return substr($ref, 21) . '[]';
//                    }
//                }
//                // no break here
            default:
                return 'text';
        }
    }


    /**
     * Generates the code based on the current user input and the specified code template files.
     * This is the main method that child classes should implement.
     * Please refer to [[\yii\gii\generators\controller\Generator::generate()]] as an example
     * on how to implement this method.
     * @return CodeFile[] a list of code files to be created.
     */
    public function generate()
    {
        $files = [];

        if ($this->generateUrls) {
            $urls = [];
            foreach ($this->generateUrls() as $urlRule) {
                $urls["{$urlRule['method']} {$urlRule['pattern']}"] = $urlRule['route'];
            }
            $files[] = new CodeFile(
                Yii::getAlias($this->urlConfigFile),
                $this->render('urls.php', [
                    'urls' => $urls,
                ])
            );
        }

        if ($this->generateControllers) {
            $controllers = $this->generateControllers();
            foreach ($controllers as $controller => $actions) {
                $className = \yii\helpers\Inflector::id2camel($controller) . 'Controller';
                $files[] = new CodeFile(
                    Yii::getAlias(Yii::$app->controllerPath . "/$className.php"),
                    $this->render('controller.php', [
                        'className' => $className,
                        'namespace' => Yii::$app->controllerNamespace,
                        'actions' => $actions,
                    ])
                );
            }
        }

        if ($this->generateModels) {
            $models = $this->generateModels();
            foreach ($models as $modelName => $model) {
                $className = $modelName;
                $files[] = new CodeFile(
                    Yii::getAlias("@app/models/$className.php"),
                    $this->render('model.php', [
                        'className' => $className,
                        'tableName' => $model['tableName'],
                        'namespace' => 'app\\models',
                        'description' => $model['description'],
                        'attributes' => $model['attributes'],
                        'relations' => $model['relations'],
                    ])
                );
            }
        }

        if ($this->generateMigrations) {
            if (!isset($models)) {
                $models = $this->generateModels();
            }
            foreach ($models as $modelName => $model) {
                // migration files get invalidated directly after generating
                // if they contain a timestamp
                // use fixed time here instead
                $m = date('ymd_000000');
                $className = "m{$m}_$modelName";
                $tableName = $model['tableName'];
                $files[] = new CodeFile(
                    Yii::getAlias("@app/migrations/$className.php"),
                    $this->render('migration.php', [
                        'className' => $className,
                        'tableName' => $tableName,
                        'attributes' => $model['attributes'],
                        'relations' => $model['relations'],
                        'description' => 'Table for ' . $modelName,
                    ])
                );
            }
        }

        return $files;
    }
}
