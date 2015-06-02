<?php
/**
 * Created by PhpStorm.
 * User: wangx08
 * Date: 2015-03-25
 * Time: 17:42
 */

namespace common\repositories\wls;

use common\models\wls\LoushuForm;
use common\models\wls\LoushuList;
use common\entities\KfsDbEntity;
use common\entities\wls\BookLouShuEntity;
use common\entities\wls\BookRelationEntity;
use common\entities\wls\BookSectionEntity;
use common\repositories\BaseRepository;
use Yii;

class LoushuRepository extends BaseRepository {

    static $GROUP_TYPE = "1";
    static $COMPANY_TYPE = "2";
    static $BUILDING_TYPE = "3";

    public function add(LoushuForm $loushuForm) {
        $bookLouShu = new BookLouShuEntity();

        $this->setBookLouShuField($bookLouShu,$loushuForm);

        $bookLouShu->created_on   =time();
        $bookLouShu->created_by   =$this->getUser();
        $bookLouShu->modified_on  =time();
        $bookLouShu->modified_by  =$this->getUser();

        $result = $bookLouShu->save();

        $loushuForm->id = $bookLouShu->attributes["id"];
        $this->insertAttchive($loushuForm);

        if (!$result) {
            throw new \Exception('新增楼书失败');
        }
        return $loushuForm;
    }

    public function update(LoushuForm $loushuForm){
        $bookLouShu = BookLouShuEntity::findOne(array(
            "id"=>$loushuForm->id,
            "token"=>$loushuForm->token
        ));

        if(is_null($bookLouShu)){
            return false;
        }
        else{
            $this->setBookLouShuField($bookLouShu,$loushuForm);
            $bookLouShu->modified_on=time();
            $bookLouShu->modified_by=$this->getUser();
            $result = $bookLouShu->save();

            $this->deleteAttchive($loushuForm->id);
            $this->insertAttchive($loushuForm);
            return $result;
        }
    }

    private function deleteAttchive($lou_shu_id){
        BookRelationEntity::deleteAll(
            "lou_shu_id=:lou_shu_id",
            array("lou_shu_id"=>$lou_shu_id)
        );
        BookSectionEntity::deleteAll(
            "lou_shu_id=:lou_shu_id",
            array("lou_shu_id"=>$lou_shu_id)
        );
    }

    private function insertAttchive(LoushuForm $loushuForm){
        $secArr = array();
        $relArr = array();
        switch($loushuForm->type){
            case "group":
                $secIndex = 1;
                foreach ($loushuForm->sections as $key => $section) {
                    $bookSection = new BookSectionEntity();
                    $bookSection->section_name = $section->name;
                    $bookSection->lou_shu_id = $loushuForm->id;
                    $bookSection->token = $loushuForm->token;
                    $bookSection->bg_pic = $section->bg;
                    $bookSection->sort_type = $secIndex++;
                    $bookSection->save();
                    $secID = $bookSection->attributes["id"];
                    $index = 1;
                    foreach ($section->items as $key1 => $item) {
                        $this->pushToRelItem($relArr,$item,$item->type,$loushuForm->id,$index++,$secID);
                    }
                }
                break;
            case "company":
                $index = 1;
                foreach ($loushuForm->items as $key => $item) {
                    $this->pushToRelItem($relArr,$item,$item->type,$loushuForm->id,$index++);
                }
                break;
            case "building":
                $this->pushToRelItem($relArr,$loushuForm->building,"building",$loushuForm->id);
                $this->pushToRelItem($relArr,$loushuForm->party,"party",$loushuForm->id);
                break;
            default:
                return null;
        }
        $command = KfsDbEntity::getDb()->createCommand();
        if(count($relArr)>0) {
            $command->batchInsert(BookRelationEntity::tableName(), array(
                "relation_type", "project_name", "project_id", "section_id", "lou_shu_id", "sort_type"
            ), $relArr)
                ->execute();
        }
    }

    private function pushToRelItem(& $itemArray,$item,$itemtype,$lou_shu_id,$sort_type = 0,$section_id = 0){
        if(!is_null($item)){
            array_push($itemArray,array($this->getDbRelationType($itemtype),$item->name,$item->id,$section_id,$lou_shu_id,$sort_type));
        }
    }

    private function setBookLouShuField(BookLouShuEntity $bookLouShu,LoushuForm $loushuForm){
        $bookLouShu->token = $loushuForm->token;
        $bookLouShu->lou_shu_name = $loushuForm->name;
        $bookLouShu->lou_shu_type = $this->getDbLouShuType($loushuForm->type);
        $bookLouShu->bg_pic = $loushuForm->bg;
        $bookLouShu->arrow_style = $loushuForm->arrowClass;
        $bookLouShu->label_style = $loushuForm->labelClass;
    }

    private function convertToLouShuForm(BookLouShuEntity $bookLouShu){
        $loushuForm = new LoushuForm();

        $loushuForm->id = $bookLouShu->id;
        $loushuForm->name = $bookLouShu->lou_shu_name;
        $loushuForm->type = $this->getClientLouShuType( $bookLouShu->lou_shu_type);
        $loushuForm->bg = $bookLouShu->bg_pic;
        $loushuForm->token = $bookLouShu->token;

        $loushuForm->arrowClass = $bookLouShu->arrow_style;
        $loushuForm->labelClass = $bookLouShu->label_style;

        return $loushuForm;
    }

    private function convertToLouShuList($bookLouShuRow){
        $loushuList = new LoushuList();
        $loushuList->id = $bookLouShuRow["id"];
        $loushuList->name = $bookLouShuRow["lou_shu_name"];
        $loushuList->type = $this->getClientLouShuType($bookLouShuRow["lou_shu_type"]);

        $loushuList->buildingCount = is_null( $bookLouShuRow["building_count"]) ? 0:$bookLouShuRow["building_count"];
        $loushuList->partyCount =is_null( $bookLouShuRow["party_count"])?0:$bookLouShuRow["party_count"];

        return $loushuList;
    }

    private function getDbLouShuType($type){
        $dbtype = 0;
        switch($type){
            case "group":
                $dbtype = LoushuRepository::$GROUP_TYPE;
                break;
            case "company":
                $dbtype = LoushuRepository::$COMPANY_TYPE;
                break;
            case "building":
                $dbtype = LoushuRepository::$BUILDING_TYPE;
                break;
        }
        return $dbtype;
    }

    private function getClientLouShuType($type){
        $clienttype = "";
        if($type == LoushuRepository::$GROUP_TYPE){
            $clienttype = "group";
        }
        elseif($type == LoushuRepository::$COMPANY_TYPE){
            $clienttype = "company";
        }
        elseif($type == LoushuRepository::$BUILDING_TYPE){
            $clienttype = "building";
        }
        return $clienttype;
    }

    public function deleteLouShu($token ,$id){
        $bookLouShu = BookLouShuEntity::findOne(array(
            "id"=>$id,
        ));
        if(is_null($bookLouShu)){
            return false;
        }
        else{
            $this->deleteAttchive($id);
            return $bookLouShu->delete();
        }
    }

    public function queryList($token, $orderBy, $asc) {
        $ascText = $asc ? SORT_ASC : SORT_DESC;

        $tableName = BookLouShuEntity::tableName();
        $tableNameRelation = BookRelationEntity::tableName();

        $command = KfsDbEntity::getDb()->createCommand("select l.id,l.lou_shu_type,l.lou_shu_name,b.building_count,p.party_count
              from ".$tableName." l
              left join (select count(1) building_count,lou_shu_id from ".$tableNameRelation." where relation_type =:building_type group by lou_shu_id) b on b.lou_shu_id = l.id
              left join (select count(1) party_count,lou_shu_id from ".$tableNameRelation." where relation_type =:party_type group by lou_shu_id) p on p.lou_shu_id = l.id
              where l.token =:token
              order by ".$orderBy." desc
              ",array(
            "token"=>$token,
            "building_type"=>RelationRepository::$BUILDING_TYPE,
            "party_type"=>RelationRepository::$PARTY_TYPE
        )) ;

        $records = $command->queryAll();

        //$records = BookLouShu::find()
        //    ->where("token =:token",["token"=>$token])
        //    ->orderBy([$orderBy => $ascText])
        //    ->all();

        $result = [];
        foreach ($records as $key => $item) {
            array_push($result, $this->convertToLouShuList($item));
        }
        return $result;
    }

    public function queryOne($token ,$id){
        $bookLouShu = BookLouShuEntity::findOne(array(
            "id"=>$id,
        ));
        if(is_null($bookLouShu)){
            return null;
        }
        else{
            $loushuForm = $this->convertToLouShuForm($bookLouShu);
            switch($loushuForm->type){
                case "group":
                    $loushuForm->sections = $this->loadSections($id);
                    $loushuForm->items = array();
                    break;
                case "company":
                    $loushuForm->sections = array();
                    $loushuForm->items = $this->loadItems($id);
                    break;
                case "building":
                    $loushuForm->sections = array();
                    $loushuForm->items = array();
                    $loushuForm->building = $this->loadBuilding($id);
                    $loushuForm->party = $this->loadParty($id);
                    break;
                default:
                    return null;
            }
            return $loushuForm;
        }
    }
    
    public function getById($id){
        $bookLouShu = BookLouShuEntity::findOne(array(
            "id"=>$id,
        ));
        
        return $bookLouShu;
    }

    private function loadSections($id){
        $secRecords = BookSectionEntity::find()
            ->where("lou_shu_id=:lou_shu_id",array(
                "lou_shu_id"=>$id,
            ))
            ->orderBy(["sort_type"=>SORT_ASC])
            ->all();
        $relRecords = BookRelationEntity::find()
            ->where("lou_shu_id=:lou_shu_id",array(
                "lou_shu_id"=>$id,
            ))
            ->orderBy(["sort_type"=>SORT_ASC])
            ->all();

        $secArr = array();
        $secMap = array();

        $i = 0;
        foreach ($secRecords as $key => $item) {

            $secArr[] = array(
                "name"=>$item->section_name,
                "id"=>$item->id,
                "bg"=>$item->bg_pic,
                "items"=>array()
            );
            $secMap[$item->id] = $i;
            $i++;
        }
        foreach ($relRecords as $key => $item) {
            $type = $this->getClientRelationType($item->relation_type);
            if(isset($secMap[$item->section_id])){
                $index = $secMap[$item->section_id];
                $sec = & $secArr[$index];
                $sec["items"][] = array(
                    "name"=>$item->project_name,
                    "id"=>$item->project_id,
                    "type"=>$type
                );
            }
        }
        return $secArr;
    }

    private function loadItems($id){
        $records = BookRelationEntity::find()
            ->where("lou_shu_id=:lou_shu_id",array(
                "lou_shu_id"=>$id,
            ))
            ->orderBy(["sort_type"=>SORT_ASC])
            ->all();
        $items=array();
        foreach ($records as $key => $item) {
            $type = $this->getClientRelationType($item->relation_type);
            array_push($items, array(
                "name"=>$item->project_name,
                "id"=>$item->project_id,
                "type"=>$type
            ));
        }
        return $items;
    }

    private function getDbRelationType($type){
        $dbtype = "";
        switch($type){
            case "building":
                $dbtype = RelationRepository::$BUILDING_TYPE;
                break;
            case "party":
                $dbtype = RelationRepository::$PARTY_TYPE;
                break;
        }
        return $dbtype;
    }
    private function getClientRelationType($relation_type){
        $type = "";
        if($relation_type == RelationRepository::$BUILDING_TYPE){
            $type = "building";
        }
        elseif($relation_type == RelationRepository::$PARTY_TYPE){
            $type = "party";
        }
        return $type;
    }

    private function loadBuilding($id){
        $records = BookRelationEntity::find()
            ->where("relation_type=:relation_type and lou_shu_id=:lou_shu_id",array(
                "lou_shu_id"=>$id,
                "relation_type"=>RelationRepository::$BUILDING_TYPE
            ))
            ->all();
        foreach ($records as $key => $item) {
            return array(
                "name"=>$item->project_name,
                "id"=>$item->project_id
            );
        }
        return null;
    }

    private function loadParty($id){
        $records = BookRelationEntity::find()
            ->where("relation_type=:relation_type and lou_shu_id=:lou_shu_id",array(
                "lou_shu_id"=>$id,
                "relation_type"=>RelationRepository::$PARTY_TYPE
            ))
            ->all();
        foreach ($records as $key => $item) {
            return array(
                "name"=>$item->project_name,
                "id"=>$item->project_id
            );
        }
        return null;
    }
}