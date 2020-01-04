<?php
namespace controller\auto;

use ank\Controller;
use ank\Utils;

/**
 * 自动化生成工具
 */
class Index extends Controller
{
    public function greateTable()
    {
        $dbConfig = $this->app->config('db_config');
        $list     = $this->db->query('SELECT * FROM information_schema.TABLES  WHERE  TABLE_SCHEMA=\'' . $dbConfig['database_name'] . '\' ORDER BY TABLE_NAME ASC');
        // $tables       = array_column($list, 'table_name');
        $modelPath    = $this->app->getAppPath() . '/model';
        $validatePath = $this->app->getAppPath() . '/validate';
        if (!file_exists($modelPath)) {
            mkdir($modelPath, 777, true);
        }
        if (!file_exists($validatePath)) {
            mkdir($validatePath, 777, true);
        }
        foreach ($list as $key => $value) {
            $value = array_change_key_case($value);
            // $fields    = $this->db->table($tableName)->getFields();
            $srcTableName = $value['table_name'];
            $tableName    = Utils::parseName(ltrim($srcTableName, $dbConfig['prefix']), 1);
            $tableComment = $value['table_comment'];
            // $fields       = $this->db->query('DESC ' . $srcTableName);
            $fieldList = $this->db->query('SELECT * FROM information_schema.COLUMNS  WHERE  TABLE_SCHEMA=\'' . $dbConfig['database_name'] . '\' AND TABLE_NAME=\'' . $srcTableName . '\' ORDER BY COLUMN_NAME ASC');
            // var_dump($fields);
            // die();
            //判断类是否存在
            $filePath = $modelPath . '/' . $tableName . '.php';
            if (!class_exists('model\\' . $tableName)) {

                $code = <<<eot
<?php
namespace model;

use ank\\Model;

/**
 * TableName: {$tableName}
 * Comment: {$tableComment}
 * Auto Greate Model
 */
class {$tableName} extends Model
{

}
eot;
                echo $filePath, PHP_EOL;
                file_put_contents($filePath, $code);
            } else {
                echo $filePath, ' => skip ', PHP_EOL;
            }

            $filePath = $validatePath . '/' . $tableName . '.php';
            if (!class_exists('validate\\' . $tableName)) {
                $maxlen = 0;
                foreach ($fieldList as $key => $value) {
                    $value  = array_change_key_case($value);
                    $maxlen = strlen($value['column_name']) > $maxlen ? strlen($value['column_name']) : $maxlen;
                }
                $valiField = [];
                $valiMsg   = [];
                foreach ($fieldList as $key => $value) {
                    $value = array_change_key_case($value);
                    //过滤掉主键
                    if ($value['column_key'] === 'PRI') {
                        continue;
                    }
                    $tvalue      = str_pad("'{$value['column_name']}'", $maxlen + 3, ' ');
                    $ruleInfo    = $this->getFieldRule($value);
                    $valiField[] = $tvalue . '=> \'' . $ruleInfo['rule'] . '\',' . $this->getFieldComment($value['column_comment']);
                    if (isset($ruleInfo['msg'])) {
                        $valiMsg = array_merge($valiMsg, $ruleInfo['msg']);
                    }
                }
                $valiField = implode(PHP_EOL . str_repeat(' ', 8), $valiField);
                $valiMsg   = implode(PHP_EOL . str_repeat(' ', 8), $valiMsg);
                $code      = <<<eot
<?php
namespace validate;

use ank\\validate;

/**
 * TableName: {$tableName}
 * Comment: {$tableComment}
 * Auto Greate Validate
 */
class {$tableName} extends Validate
{
    protected \$rule = [
        {$valiField}
    ];
    protected \$message = [
        {$valiMsg}
    ];
}
eot;
                echo $filePath, PHP_EOL;
                file_put_contents($filePath, $code);
            } else {
                echo $filePath, ' => skip ', PHP_EOL;
            }
        }
        echo 'greate table success';
    }

    private function getFieldComment($value)
    {
        if ($value) {
            return ' // ' . $value;
        }

        return '';

    }

    private function getFieldRule($value)
    {
        if ($value['is_nullable'] === 'NO') {
            if ($value['column_default'] === null) {
                return ['rule' => 'require', 'msg' => [
                    '\'' . $value['column_name'] . '.require\' => \'不能为空\',',
                ]];
            } elseif (in_array($value['data_type'], ['int', 'bigint', 'float', 'double', 'tinyint', 'smallint'])) {
                return ['rule' => 'number', 'msg' => [
                    '\'' . $value['column_name'] . '.number\' => \'数字格式不正确\',',
                ]];
            } elseif (in_array($value['data_type'], ['datetime', 'timestamp'])) {
                return ['rule' => 'require|date', 'msg' => [
                    '\'' . $value['column_name'] . '.require\' => \'不能为空\',',
                    '\'' . $value['column_name'] . '.date\' => \'日期格式不正确\',',
                ]];
            }

        }

        return ['rule' => ''];

    }
}
