<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Model\DataObject\ClassDefinition;

use Pimcore\Cache;
use Pimcore\Event\DataObjectCustomLayoutEvents;
use Pimcore\Event\Model\DataObject\CustomLayoutEvent;
use Pimcore\Logger;
use Pimcore\Model;
use Pimcore\Model\DataObject;

/**
 * @method \Pimcore\Model\DataObject\ClassDefinition\CustomLayout\Dao getDao()
 */
class CustomLayout extends Model\AbstractModel
{
    use DataObject\ClassDefinition\Helper\VarExport;

    /**
     * @var string|null
     */
    protected $id;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $description;

    /**
     * @var int
     */
    protected $creationDate;

    /**
     * @var int
     */
    protected $modificationDate;

    /**
     * @var int
     */
    protected $userOwner;

    /**
     * @var int
     */
    protected $userModification;

    /**
     * @var string
     */
    protected $classId;

    /**
     * @var Layout|null
     */
    protected $layoutDefinitions;

    /**
     * @var int
     */
    protected $default;

    /**
     * @param string $id
     *
     * @return null|CustomLayout
     */
    public static function getById($id)
    {
        $cacheKey = 'customlayout_' . $id;

        try {
            $customLayout = \Pimcore\Cache\Runtime::get($cacheKey);
            if (!$customLayout) {
                throw new \Exception('Custom Layout in registry is null');
            }
        } catch (\Exception $e) {
            try {
                $customLayout = new self();
                $customLayout->getDao()->getById($id);
                \Pimcore\Cache\Runtime::set($cacheKey, $customLayout);
            } catch (Model\Exception\NotFoundException $e) {
                return null;
            }
        }

        return $customLayout;
    }

    /**
     * @param string $name
     *
     * @return null|CustomLayout
     *
     * @throws \Exception
     */
    public static function getByName(string $name)
    {
        $customLayout = new self();
        $id = $customLayout->getDao()->getIdByName($name);

        return self::getById($id);
    }

    /**
     * @param string $name
     * @param string $classId
     *
     * @return null|CustomLayout
     *
     * @throws \Exception
     */
    public static function getByNameAndClassId(string $name, $classId)
    {
        $customLayout = new self();
        $id = $customLayout->getDao()->getIdByNameAndClassId($name, $classId);

        return self::getById($id);
    }

    /**
     * @param string $field
     *
     * @return Data|null
     */
    public function getFieldDefinition($field)
    {
        /**
         * @param string $key
         * @param Data|Layout $definition
         *
         * @return Data|null
         */
        $findElement = static function ($key, $definition) use (&$findElement) {
            if ($definition->getName() === $key) {
                return $definition;
            }
            if (method_exists($definition, 'getChildren')) {
                foreach ($definition->getChildren() as $child) {
                    if ($childDefinition = $findElement($key, $child)) {
                        return $childDefinition;
                    }
                }
            }

            return null;
        };

        return $findElement($field, $this->getLayoutDefinitions());
    }

    /**
     * @param array $values
     *
     * @return CustomLayout
     */
    public static function create($values = [])
    {
        $class = new self();
        $class->setValues($values);

        if (!$class->getId()) {
            $class->getDao()->getNewId();
        }

        return $class;
    }

    /**
     * @param bool $saveDefinitionFile
     */
    public function save($saveDefinitionFile = true)
    {
        $isUpdate = $this->exists();

        if ($isUpdate && !$this->isWritable()) {
            throw new \Exception('definitions in config/pimcore folder cannot be overwritten');
        }

        if ($isUpdate) {
            \Pimcore::getEventDispatcher()->dispatch(new CustomLayoutEvent($this), DataObjectCustomLayoutEvents::PRE_UPDATE);
        } else {
            \Pimcore::getEventDispatcher()->dispatch(new CustomLayoutEvent($this), DataObjectCustomLayoutEvents::PRE_ADD);
        }

        $this->setModificationDate(time());

        // create directory if not exists
        if (!is_dir(PIMCORE_CUSTOMLAYOUT_DIRECTORY)) {
            \Pimcore\File::mkdir(PIMCORE_CUSTOMLAYOUT_DIRECTORY);
        }

        $this->getDao()->save($isUpdate);

        $this->saveCustomLayoutFile($saveDefinitionFile);

        // empty custom layout cache
        try {
            Cache::clearTag('customlayout_' . $this->getId());
        } catch (\Exception $e) {
        }
    }

    /**
     * @param bool $saveDefinitionFile
     *
     * @throws \Exception
     */
    private function saveCustomLayoutFile($saveDefinitionFile = true)
    {
        // save definition as a php file
        $definitionFile = $this->getDefinitionFile();
        if (!is_writable(dirname($definitionFile)) || (is_file($definitionFile) && !is_writable($definitionFile))) {
            throw new \Exception(
                'Cannot write definition file in: '.$definitionFile.' please check write permission on this directory.'
            );
        }

        $infoDocBlock = $this->getInfoDocBlock();

        $clone = clone $this;
        $clone->setDao(null);
        unset($clone->fieldDefinitions);

        self::cleanupForExport($clone->layoutDefinitions);

        if ($saveDefinitionFile) {
            $data = to_php_data_file_format($clone, $infoDocBlock);

            \Pimcore\File::putPhpFile($definitionFile, $data);
        }
    }

    /**
     * @internal
     *
     * @return bool
     */
    public function isWritable(): bool
    {
        if (getenv('PIMCORE_CLASS_DEFINITION_WRITABLE')) {
            return true;
        }

        return !str_starts_with($this->getDefinitionFile(), PIMCORE_CUSTOM_CONFIGURATION_DIRECTORY);
    }

    /**
     * @internal
     *
     * @param string|null $id
     *
     * @return string
     */
    public function getDefinitionFile($id = null)
    {
        if (!$id) {
            $id = $this->getId();
        }

        $customFile = PIMCORE_CUSTOM_CONFIGURATION_DIRECTORY . '/classes/customlayouts/custom_definition_'. $id .'.php';
        if (is_file($customFile)) {
            return $customFile;
        } else {
            return PIMCORE_CUSTOMLAYOUT_DIRECTORY.'/custom_definition_'. $id .'.php';
        }
    }

    /**
     * @param Data|Layout|null $data
     */
    private static function cleanupForExport(&$data)
    {
        if (is_null($data)) {
            return;
        }

        if ($data instanceof DataObject\ClassDefinition\Data\VarExporterInterface) {
            $blockedVars = $data->resolveBlockedVars();
            foreach ($blockedVars as $blockedVar) {
                if (isset($data->{$blockedVar})) {
                    unset($data->{$blockedVar});
                }
            }

            if (isset($data->blockedVarsForExport)) {
                unset($data->blockedVarsForExport);
            }
        }

        if (method_exists($data, 'getChildren')) {
            $children = $data->getChildren();
            if (is_array($children)) {
                foreach ($children as $child) {
                    self::cleanupForExport($child);
                }
            }
        }
    }

    /**
     * @internal
     *
     * @return string
     */
    protected function getInfoDocBlock()
    {
        $cd = '/**' . "\n";

        if ($this->getDescription()) {
            $description = str_replace(['/**', '*/', '//'], '', $this->getDescription());
            $description = str_replace("\n", "\n* ", $description);

            $cd .= '* '.$description."\n";
        }
        $cd .= '*/';

        return $cd;
    }

    /**
     * @internal
     *
     * @param string $classId
     *
     * @return int|null
     */
    public static function getIdentifier($classId)
    {
        try {
            $customLayout = new self();
            $identifier = $customLayout->getDao()->getLatestIdentifier($classId);

            return $identifier;
        } catch (\Exception $e) {
            Logger::error($e);

            return null;
        }
    }

    public function delete()
    {
        // empty object cache
        try {
            Cache::clearTag('customlayout_' . $this->getId());
        } catch (\Exception $e) {
        }

        // empty output cache
        try {
            Cache::clearTag('output');
        } catch (\Exception $e) {
        }

        $this->getDao()->delete();
    }

    /**
     * @return bool
     */
    public function exists()
    {
        if (is_null($this->getId())) {
            return false;
        }
        $name = $this->getDao()->getNameById($this->getId());

        return is_string($name);
    }

    /**
     * @return string|null
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return int
     */
    public function getCreationDate()
    {
        return $this->creationDate;
    }

    /**
     * @return int
     */
    public function getModificationDate()
    {
        return $this->modificationDate;
    }

    /**
     * @return int
     */
    public function getUserOwner()
    {
        return $this->userOwner;
    }

    /**
     * @return int
     */
    public function getUserModification()
    {
        return $this->userModification;
    }

    /**
     * @param string $id
     *
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @param string $name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return int
     */
    public function getDefault()
    {
        return $this->default;
    }

    /**
     * @param int $default
     *
     * @return $this
     */
    public function setDefault($default)
    {
        $this->default = (int)$default;

        return $this;
    }

    /**
     * @param int $creationDate
     *
     * @return $this
     */
    public function setCreationDate($creationDate)
    {
        $this->creationDate = (int) $creationDate;

        return $this;
    }

    /**
     * @param int $modificationDate
     *
     * @return $this
     */
    public function setModificationDate($modificationDate)
    {
        $this->modificationDate = (int) $modificationDate;

        return $this;
    }

    /**
     * @param int $userOwner
     *
     * @return $this
     */
    public function setUserOwner($userOwner)
    {
        $this->userOwner = (int) $userOwner;

        return $this;
    }

    /**
     * @param int $userModification
     *
     * @return $this
     */
    public function setUserModification($userModification)
    {
        $this->userModification = (int) $userModification;

        return $this;
    }

    /**
     * @param string $description
     *
     * @return $this
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @param Layout|null $layoutDefinitions
     */
    public function setLayoutDefinitions($layoutDefinitions)
    {
        $this->layoutDefinitions = $layoutDefinitions;
    }

    /**
     * @return Layout|null
     */
    public function getLayoutDefinitions()
    {
        return $this->layoutDefinitions;
    }

    /**
     * @param string $classId
     */
    public function setClassId($classId)
    {
        $this->classId = $classId;
    }

    /**
     * @return string
     */
    public function getClassId()
    {
        return $this->classId;
    }
}
