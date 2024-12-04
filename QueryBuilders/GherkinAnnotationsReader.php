<?php
namespace axenox\BDT\QueryBuilders;

use exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder;
use exface\Core\CommonLogic\DataQueries\PhpAnnotationsDataQuery;
use exface\Core\QueryBuilders\PhpAnnotationsReader;
use Wingu\OctopusCore\Reflection\ReflectionMethod;
use Wingu\OctopusCore\Reflection\ReflectionClass;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\Exceptions\QueryBuilderException;
use Wingu\OctopusCore\Reflection\ReflectionDocComment;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\DataSources\DataQueryResultDataInterface;
use exface\Core\CommonLogic\DataQueries\DataQueryResultData;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use Wingu\OctopusCore\Reflection\ReflectionProperty;
use Wingu\OctopusCore\Reflection\ReflectionConstant;

/**
 * A query builder to read annotations for PHP classes, their methods, properties and constants.
 * 
 * Reads general comments and any specified annotation tags. See meta objects
 * `UXON_ENTITY_ANNOTATION` and `UXON_PROPERTY_ANNOTATION` of the Core for examples.
 * 
 * The type/level of annotations to read can be specified in the data address property
 * `annotation_level`.
 *
 * @author Andrej Kabachnik
 *        
 */
class GherkinAnnotationsReader extends PhpAnnotationsReader
{

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::read()
     */
    public function read(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        $result_rows = array();
        $annotation_level = $this->getAnnotationLevel();
        
        // Check if force filtering is enabled
        if (count($this->getFilters()->getFiltersAndNestedGroups()) < 1) {
            throw new QueryBuilderException('Cannot use query builder PhpAnnotationReader without filters: no files to read!');
        }
        
        $query = $data_connection->query($this->buildQuery());
        $this->setLastQuery($query);
        if ($className = $query->getClassNameWithNamespace()) {
            $class = new ReflectionClass($className);
            
            // Read method annotations
            if (! $annotation_level || $annotation_level == $this::ANNOTATION_LEVEL_METHOD) {
                $methods = $class->getOwnMethods();
                // $methods = $class->getMethods();
                foreach ($methods as $method) {
                    $rows = $this->buildRowsFromMethodAllTags($class, $method);
                    $result_rows = array_merge($result_rows, $rows);
                }
            }
            
            $result_rows = $this->applyFilters($result_rows);
            $resultTotalRowCounter = count($result_rows);
            $result_rows = $this->applySorting($result_rows);
            $result_rows = $this->applyPagination($result_rows);
        }
        
        $rowCnt = count($result_rows);
        if (! $resultTotalRowCounter) {
            $resultTotalRowCounter = $rowCnt;
        }
        
        return new DataQueryResultData($result_rows, $rowCnt, ($resultTotalRowCounter > $rowCnt + $this->getOffset()), $resultTotalRowCounter);
    }

    /**
     *
     * @param ReflectionClass $class            
     * @param ReflectionDocComment $comment            
     * @param array $row            
     * @return string
     */
    protected function buildRowsFromCommentAllTags(ReflectionClass $class, ReflectionDocComment $comment, $row) : array
    {
        // Loop through all attributes to find exactly matching annotations
        $rows = [];
        $qparts = [];
        foreach ($this->getAttributesMissingInRow($row) as $qpart) {
            // Only process attributes with data addresses
            if (! $qpart->getDataAddress())
                continue;
            // Do not overwrite already existent values (could happen when processing a parent class)
            if (array_key_exists($qpart->getColumnKey(), $row))
                continue;

            $qparts[] = $qpart;
        }
            
            // First look through the real tags for exact matches
        try {
            foreach ($comment->getAnnotationsCollection()->getAnnotations() as $r => $tag) {
                foreach ($qparts as $qpart) {
                    $addr = $qpart->getDataAddress();
                    $addrTags = explode('|', $addr);
                    if (in_array($tag->getTagName(), $addrTags)) {
                        $colKey = $qpart->getColumnKey();
                        $colVal = $tag->getTagName() . ' ' . $tag->getDescription();
                        if ($colVal !== null) {
                            $rows[$r][$colKey] = $colVal;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            throw new DataQueryFailedError($this->getLastQuery(), 'Cannot read annotation "' . $comment->getOriginalDocBlock() . '": ' . $e->getMessage(), null, $e);
        } catch (\ErrorException $e) {
            throw new DataQueryFailedError($this->getLastQuery(), 'Cannot read annotation "' . $comment->getOriginalDocBlock() . '": ' . $e->getMessage(), null, $e);
        }
        return $rows;
    }

    /**
     *
     * @param ReflectionClass $class            
     * @param ReflectionMethod $property            
     * @param array $row            
     * @return string
     */
    protected function buildRowsFromMethodAllTags(ReflectionClass $class, ReflectionMethod $method) : array
    {
        // First look for exact matches among the tags within the comment
        $comment = $method->getReflectionDocComment(self::COMMENT_TRIM_LINE_PATTERN);
        $rows = $this->buildRowsFromCommentAllTags($class, $comment, []);
        
        // If at least one exact match was found, this method is a valid row.
        // Now add enrich the row with general comment fields (description, etc.) and fields from the class level
        
        foreach ($rows as $i => $row) {
            if (! $this->getIgnoreCommentsWithoutMatchingTags($this->getMainObject()) || count($row) > 0) {
                $rows[$i] = $this->buildRowFromClass($class, $row);
                $rows[$i] = $this->buildRowFromComment($class, $comment, $rows[$i]);
                // Add the FQSEN (Fully Qualified Structural Element Name) if we are on method level
                foreach ($this->getAttributesMissingInRow($rows[$i]) as $qpart) {
                    if (strcasecmp($qpart->getDataAddress(), 'fqsen') === 0) {
                        $rows[$i][$qpart->getColumnKey()] = $class->getName() . '::' . $method->getName() . '()';
                    }
                }
            }
        }
        
        return $rows;
    }
}