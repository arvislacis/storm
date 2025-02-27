<?php namespace Winter\Storm\Database\Traits;

use Exception;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Illuminate\Contracts\Validation\Rule;
use Winter\Storm\Support\Str;
use Winter\Storm\Database\ModelException;
use Winter\Storm\Support\Facades\Input;
use Winter\Storm\Support\Facades\Validator;

trait Validation
{
    /**
     * @var array The rules to be applied to the data.
     *
     * public $rules = [];
     */

    /**
     * @var array The array of custom attribute names.
     *
     * public $attributeNames = [];
     */

    /**
     * @var array The array of custom error messages.
     *
     * public $customMessages = [];
     */

    /**
     * @var bool Makes the validation procedure throw an {@link Winter\Storm\Database\ModelException}
     * instead of returning false when validation fails.
     *
     * public $throwOnValidation = true;
     */

    /**
     * @var \Illuminate\Support\MessageBag The message bag instance containing validation error messages
     */
    protected $validationErrors;

    /**
     * @var array Default custom attribute names.
     */
    protected $validationDefaultAttrNames = [];

    /**
     * Boot the validation trait for this model.
     *
     * @return void
     */
    public static function bootValidation()
    {
        if (!property_exists(get_called_class(), 'rules')) {
            throw new Exception(sprintf(
                'You must define a $rules property in %s to use the Validation trait.',
                get_called_class()
            ));
        }

        static::validating(function ($model) {
            if ($model->methodExists('beforeValidate')) {
                // Register the method as a listener with default priority
                // to allow for complete control over the execution order
                $model->bindEventOnce('model.beforeValidate', [$model, 'beforeValidate']);
            }

            /**
             * @event model.beforeValidate
             * Called before the model is validated
             *
             * Example usage:
             *
             *     $model->bindEvent('model.beforeValidate', function () use (\Winter\Storm\Database\Model $model) {
             *         // Prevent anything from validating ever!
             *         return false;
             *     });
             *
             */
            return $model->fireEvent('model.beforeValidate', halt: true);
        });

        static::validated(function ($model) {
            if ($model->methodExists('afterValidate')) {
                // Register the method as a listener with default priority
                // to allow for complete control over the execution order
                $model->bindEventOnce('model.afterValidate', [$model, 'afterValidate']);
            }

            /**
             * @event model.afterValidate
             * Called after the model is validated
             *
             * Example usage:
             *
             *     $model->bindEvent('model.afterValidate', function () use (\Winter\Storm\Database\Model $model) {
             *         \Log::info("{$model->name} successfully passed validation");
             *     });
             *
             */
            return $model->fireEvent('model.afterValidate', halt: true);
        });

        static::extend(function ($model) {
            $model->bindEvent('model.saveInternal', function ($data, $options) use ($model) {
                /*
                 * If forcing the save event, the beforeValidate/afterValidate
                 * events should still fire for consistency. So validate an
                 * empty set of rules and messages.
                 */
                $force = array_get($options, 'force', false);
                if ($force) {
                    $valid = $model->validate([], []);
                }
                else {
                    $valid = $model->validate();
                }

                if (!$valid) {
                    return false;
                }
            }, 500);
        });
    }

    /**
     * Programatically sets multiple validation attribute names.
     * @param array $attributeNames
     * @return void
     */
    public function setValidationAttributeNames($attributeNames)
    {
        $this->validationDefaultAttrNames = $attributeNames;
    }

    /**
     * Programatically sets the validation attribute names, will take lower priority
     * to model defined attribute names found in `$attributeNames`.
     * @param string $attr
     * @param string $name
     * @return void
     */
    public function setValidationAttributeName($attr, $name)
    {
        $this->validationDefaultAttrNames[$attr] = $name;
    }

    /**
     * Returns the model data used for validation.
     * @return array
     */
    protected function getValidationAttributes()
    {
        $attributes = $this->getAttributes();
        
        /**
         * @event model.getValidationAttributes
         * Called when fetching the model attributes to validate the model
         *
         * Example usage from TranslatableBehavior class:
         *
         *     $model->bindEvent('model.getValidationAttributes', function ($attributes) {
         *         $locale = $this->translateContext();
         *         if ($locale !== $this->translatableDefault) {
         *             return array_merge($attributes, $this->getTranslateDirty($locale));
         *         }
         *     });
         *
         */
        if (($validationAttributes = $this->fireEvent('model.getValidationAttributes', [$attributes], true)) !== null) {
            return $validationAttributes;
        }

        return $attributes;
    }

    /**
     * Attachments validate differently to their simple values.
     */
    protected function getRelationValidationValue($relationName)
    {
        $relationType = $this->getRelationType($relationName);

        if ($relationType === 'attachOne' || $relationType === 'attachMany') {
            return $this->$relationName()->getValidationValue();
        }

        return $this->getRelationValue($relationName);
    }

    /**
     * Instantiates the validator used by the validation process, depending if the class
     * is being used inside or outside of Laravel. Optional connection string to make
     * the validator use a different database connection than the default connection.
     *
     * @return \Illuminate\Contracts\Validation\Validator
     * @phpstan-return \Illuminate\Validation\Validator
     */
    protected static function makeValidator($data, $rules, $customMessages, $attributeNames, $connection = null)
    {
        /** @var \Illuminate\Validation\Validator $validator */
        $validator = Validator::make($data, $rules, $customMessages, $attributeNames);

        if ($connection !== null) {
            $verifier = App::make('validation.presence');
            $verifier->setConnection($connection);
            $validator->setPresenceVerifier($verifier);
        }

        return $validator;
    }

    /**
     * Force save the model even if validation fails.
     * @return bool
     */
    public function forceSave($options = null, $sessionKey = null)
    {
        $this->sessionKey = $sessionKey;
        return $this->saveInternal(['force' => true] + (array) $options);
    }

    /**
     * Validate the model instance
     * @return bool
     */
    public function validate($rules = null, $customMessages = null, $attributeNames = null)
    {
        if ($this->validationErrors === null) {
            $this->validationErrors = new MessageBag;
        }

        $throwOnValidation = property_exists($this, 'throwOnValidation')
            ? $this->throwOnValidation
            : true;

        if ($this->fireModelEvent('validating') === false) {
            if ($throwOnValidation) {
                throw new ModelException($this);
            }

            return false;
        }

        /*
         * Perform validation
         */
        $rules = is_null($rules) ? $this->rules : $rules;
        $rules = $this->processValidationRules($rules);
        $success = true;

        if (!empty($rules)) {
            $data = $this->getValidationAttributes();

            /*
             * Decode jsonable attribute values
             */
            foreach ($this->getJsonable() as $jsonable) {
                $data[$jsonable] = $this->getAttribute($jsonable);
            }

            /*
             * Add relation values, if specified.
             */
            foreach ($rules as $attribute => $rule) {
                if (
                    !$this->hasRelation($attribute) ||
                    array_key_exists($attribute, $data)
                ) {
                    continue;
                }

                $data[$attribute] = $this->getRelationValidationValue($attribute);
            }

            /*
             * Compatibility with Hashable trait:
             * Remove all hashable values regardless, add the original values back
             * only if they are part of the data being validated.
             */
            if (method_exists($this, 'getHashableAttributes')) {
                $cleanAttributes = array_diff_key($data, array_flip($this->getHashableAttributes()));
                $hashedAttributes = array_intersect_key($this->getOriginalHashValues(), $data);
                $data = array_merge($cleanAttributes, $hashedAttributes);
            }

            /*
             * Compatibility with Encryptable trait:
             * Remove all encryptable values regardless, add the original values back
             * only if they are part of the data being validated.
             */
            if (method_exists($this, 'getEncryptableAttributes')) {
                $cleanAttributes = array_diff_key($data, array_flip($this->getEncryptableAttributes()));
                $encryptedAttributes = array_intersect_key($this->getOriginalEncryptableValues(), $data);
                $data = array_merge($cleanAttributes, $encryptedAttributes);
            }

            /*
             * Custom messages, translate internal references
             */
            if (property_exists($this, 'customMessages') && is_null($customMessages)) {
                $customMessages = $this->customMessages;
            }

            if (is_null($customMessages)) {
                $customMessages = [];
            }

            $translatedCustomMessages = [];
            foreach ($customMessages as $rule => $customMessage) {
                $translatedCustomMessages[$rule] = Lang::get($customMessage);
            }

            $customMessages = $translatedCustomMessages;

            /*
             * Attribute names, translate internal references
             */
            if (is_null($attributeNames)) {
                $attributeNames = [];
            }

            $attributeNames = array_merge($this->validationDefaultAttrNames, $attributeNames);

            if (property_exists($this, 'attributeNames')) {
                $attributeNames = array_merge($this->attributeNames, $attributeNames);
            }

            $translatedAttributeNames = [];
            foreach ($attributeNames as $attribute => $attributeName) {
                $translatedAttributeNames[$attribute] = Lang::get($attributeName);
            }

            $attributeNames = $translatedAttributeNames;

            /*
             * Translate any externally defined attribute names
             */
            $translations = Lang::get('validation.attributes');
            if (is_array($translations)) {
                $attributeNames = array_merge($translations, $attributeNames);
            }

            /*
             * Hand over to the validator
             */
            $validator = self::makeValidator(
                $data,
                $rules,
                $customMessages,
                $attributeNames,
                $this->getConnectionName()
            );

            $success = $validator->passes();

            if ($success) {
                if ($this->validationErrors->count() > 0) {
                    $this->validationErrors = new MessageBag;
                }
            }
            else {
                $this->validationErrors = $validator->messages();
                if (Input::hasSession()) {
                    Input::flash();
                }
            }
        }

        $this->fireModelEvent('validated', false);

        if (!$success && $throwOnValidation) {
            throw new ModelException($this);
        }

        return $success;
    }

    /**
     * Process rules
     */
    protected function processValidationRules($rules)
    {
        /*
         * Run through field names and convert array notation field names to dot notation
         */
        $rules = $this->processRuleFieldNames($rules);

        foreach ($rules as $field => $ruleParts) {
            /*
             * Trim empty rules
             */
            if (is_string($ruleParts) && trim($ruleParts) == '') {
                unset($rules[$field]);
                continue;
            }

            /*
             * Normalize rulesets
             */
            if (!is_array($ruleParts)) {
                $ruleParts = explode('|', $ruleParts);
            }

            /*
             * Analyse each rule individually
             */
            foreach ($ruleParts as $key => $rulePart) {
                if ($rulePart instanceof Rule) {
                    continue;
                }

                /*
                 * Remove primary key unique validation rule if the model already exists
                 */
                if (($rulePart === 'unique' || starts_with($rulePart, 'unique:'))) {
                    $ruleParts[$key] = $this->processValidationUniqueRule($rulePart, $field);
                }
                /*
                 * Look for required:create and required:update rules
                 */
                elseif (starts_with($rulePart, 'required:create') && $this->exists) {
                    unset($ruleParts[$key]);
                }
                elseif (starts_with($rulePart, 'required:update') && !$this->exists) {
                    unset($ruleParts[$key]);
                }
            }

            $rules[$field] = $ruleParts;
        }

        return $rules;
    }

    /**
     * Processes field names in a rule array.
     *
     * Converts any field names using array notation (ie. `field[child]`) into dot notation (ie. `field.child`)
     *
     * @param array $rules Rules array
     * @return array
     */
    protected function processRuleFieldNames($rules)
    {
        $processed = [];

        foreach ($rules as $field => $ruleParts) {
            $fieldName = $field;

            if (preg_match('/^.*?\[.*?\]/', $fieldName)) {
                $fieldName = str_replace('[]', '.*', $fieldName);
                $fieldName = str_replace(['[', ']'], ['.', ''], $fieldName);
            }

            $processed[$fieldName] = $ruleParts;
        }

        return $processed;
    }

    /**
     * Rebuilds the unique validation rule to force for the existing ID
     * @param string $definition
     * @param string $fieldName
     * @return string
     */
    protected function processValidationUniqueRule($definition, $fieldName)
    {
        $connection = '';
        list(
            $table,
            $column,
            $ignoreValue,
            $ignoreColumn,
            $whereColumn,
            $whereValue
        ) = array_pad(explode(',', $definition, 6), 6, null);

        // Remove unique or unique: from the table name
        $table = Str::after(Str::after($table, 'unique'), ':');

        // Support table, connection.table, and null value
        if (Str::contains($table, '.')) {
            $connectionDetails = explode('.', $table);
            $connection = $connectionDetails[0];
            $table = $connectionDetails[1];
        } elseif (empty($table)) {
            $table = $this->getTable();
        }
        if (empty($connection)) {
            $connection = $this->getConnectionName();
        }

        $column = $column ?: $fieldName;
        $ignoreColumn = $ignoreColumn ?: $this->getKeyName();
        $ignoreValue = ($ignoreValue && $ignoreValue !== 'NULL') ? $ignoreValue : $this->{$ignoreColumn};
        if (is_null($ignoreValue)) {
            $ignoreValue = 'NULL';
        }

        $params = ["unique:{$connection}.{$table}", $column, $ignoreValue, $ignoreColumn];

        if ($whereColumn) {
            $params[] = $whereColumn;
        }
        if ($whereValue) {
            $params[] = $whereValue;
        }

        return implode(',', $params);
    }

    /**
     * Determines if an attribute is required based on the validation rules.
     * @param  string  $attribute
     * @param boolean $checkDependencies Checks the attribute dependencies (for required_if & required_with rules). Note that it will only be checked up to the next level, if another dependent rule is found then it will just assume the field is required
     * @return boolean
     */
    public function isAttributeRequired($attribute, $checkDependencies = true)
    {
        if (!isset($this->rules[$attribute])) {
            return false;
        }

        $ruleset = $this->rules[$attribute];

        if (is_array($ruleset)) {
            $ruleset = implode('|', $ruleset);
        }

        if (strpos($ruleset, 'required:create') !== false && $this->exists) {
            return false;
        }

        if (strpos($ruleset, 'required:update') !== false && !$this->exists) {
            return false;
        }

        if (strpos($ruleset, 'required_with') !== false) {
            if ($checkDependencies) {
                $requiredWith = substr($ruleset, strpos($ruleset, 'required_with') + 14);
                if (strpos($requiredWith, '|') !== false) {
                    $requiredWith = substr($requiredWith, 0, strpos($requiredWith, '|'));
                }
                return $this->isAttributeRequired($requiredWith, false);
            } else {
                return true;
            }
        }

        if (strpos($ruleset, 'required_if') !== false) {
            if ($checkDependencies) {
                $requiredIf = substr($ruleset, strpos($ruleset, 'required_if') + 12);
                $requiredIf = substr($requiredIf, 0, strpos($requiredIf, ','));
                return $this->isAttributeRequired($requiredIf, false);
            } else {
                return true;
            }
        }

        return strpos($ruleset, 'required') !== false;
    }

    /**
     * Get validation error message collection for the Model
     * @return \Illuminate\Support\MessageBag
     */
    public function errors()
    {
        return $this->validationErrors;
    }

    /**
     * Create a new native event for handling beforeValidate().
     * @param \Closure|string $callback
     * @return void
     */
    public static function validating($callback)
    {
        static::registerModelEvent('validating', $callback);
    }

    /**
     * Create a new native event for handling afterValidate().
     * @param \Closure|string $callback
     * @return void
     */
    public static function validated($callback)
    {
        static::registerModelEvent('validated', $callback);
    }
}
