<?php

abstract class Widgets {

    protected $SphinxClient; //sphinxAPI
    protected $Settings;

    abstract function AddQuery($Options);

    public function __construct($SphinxClient, $Settings) {
        $this->SphinxClient = $SphinxClient;
        $this->Settings = $Settings;
        $this->Connect();
    }

    private function Connect() {
        $this->SphinxClient->SetServer($this->Settings['Install']->Host, (int) $this->Settings['Install']->Port); //must start searchd in order to connect...or else conection will be refused
        $this->SphinxClient->SetMaxQueryTime((int) $this->Settings['Admin']->MaxQueryTime); // Sets maximum search query time, in milliseconds. Default valus is 0 which means "do not limit".
        $this->SphinxClient->SetMatchMode(SPH_MATCH_EXTENDED2); //use this since using boolean operators
    }

    protected function SortIDMatches($SphinxMatches) {
        $Comments = array();
        $Discussions = array();
        $Offset = 0; //keep the order of sphinx result

        foreach ($SphinxMatches as $Row) { //
            if ($Row->iscomment)
                $Comments [$Offset] = $Row->docid; //comments are offset by taking the max of discussion and adding it
            else
                $Discussions [$Offset] = $Row->docid;
            $Offset++;
        }

        return array('Comment' => $Comments, 'Discussion' => $Discussions);
    }

    /**
     *
     * Gets the SQL data for each ID that is returned from sphinx
     * @todo what happens if sphinx returns an discussion/comment ID that no longer exists in database?
     * @param type $Mode
     * @param type $SphinxMatches
     * @return type
     */
    protected function GetSQLData($Mode, $SphinxMatches) {
        $Return = array(); //use this to sort results based on what sphinx returned them in (this IS IMPORTANT!)
        $Matches = $this->SortIDMatches($SphinxMatches); //return the sorted IDs (recall that a docid of '5' is duplicate in both comments/discussions..there is no unique ID
        switch (strtolower($Mode)) {
            case 'sleak':
                $Result = $this->PrepareSleak($Matches);
                break;
            case 'full':
                $Result = $this->PrepareFull($Matches);
                break;
            case 'table':
                $Result = $this->PrepareTable($Matches);
                break;
            case 'simple':
            default:
                $Result = $this->PrepareSimple($Matches);
                break;
        }
        return $Result;
    }

    protected function PrepareSleak($Matches) {

        return $this->PrepareFull($Matches);
    }

    protected function PrepareSimple($Matches) {
        $SQL = Gdn::SQL();
        $Comment = $SQL->Select('c.DiscussionID')
                ->Select('d.Name as Title, c.Body as Body, d.Body as DiscussionBody')
                ->From('Comment as c')
                ->Join('Discussion as d', 'c.DiscussionID = d.DiscussionID')
                ->WhereIn('c.CommentID', $Matches['Comment'])
                ->Get()
                ->Result()
        ;
        $Discussion = $SQL->Select('d.DiscussionID')
                ->Select('d.Name as Title, d.Body as Body, d.Body as DiscussionBody')
                ->From('Discussion as d')
                ->WhereIn('d.DiscussionID', $Matches['Discussion'])
                ->Get()
                ->Result()
        ;

        return $this->SortTableResults($Matches, $Comment, $Discussion);
    }

    //display the following:
    protected function PrepareTable($Matches) {
        //print_r($Matches); die;
        $SQL = Gdn::SQL();
        $WhereIn = $this->WhereIn('c.DiscussionID', $Matches['Comment']);
        //This query checks if the LastUserID and LastCommentID are Null.
        $Comment = $SQL->Query('
            select c.DiscussionID as `DiscussionID`, c.InsertUserID as `InsertUserID`, c.DateInserted as `DateInserted`, c.Body as Body,
            IF(d.LastCommentUserID IS NULL, d.InsertUserID, d.LastCommentUserID) as `LastCommentUserID`, d.DateLastComment as `DateLastComment`, if(d.LastCommentID is NULL, d.DiscussionID, d.LastCommentID) as `LastCommentID`,
            d.CountComments as `CountComments`, d.CountViews as `CountViews`, d.Name as `Title`,d.Body as DiscussionBody,
            u.Name as `UserName`, u.UserID as `UserID`,
            Lu.Name as `LastUserName`, Lu.UserID as `LastUserID`,
            cat.Name as `CatName`, cat.UrlCode as `CatUrlCode`
            from GDN_Comment c
            inner join GDN_Discussion d on d.DiscussionID = c.DiscussionID
            left join GDN_User Lu on Lu.UserID = (IF(d.LastCommentUserID IS NULL, d.InsertUserID, d.LastCommentUserID))
            left join GDN_User u on u.UserID = d.InsertUserID
            inner join GDN_Category cat on cat.CategoryID = d.CategoryID
            where ' . $WhereIn . '
            ')->Result();



        $WhereIn = $this->WhereIn('d.DiscussionID', $Matches['Discussion']); //only retrieving discussions
        //This query checks if the LastUserID and LastCommentID are Null.
        $Discussion = $SQL->Query('
            select d.DiscussionID as `DiscussionID`, d.InsertUserID as `InsertUserID`, d.DateInserted as `DateInserted`, IF(d.LastCommentUserID IS NULL, d.InsertUserID, d.LastCommentUserID) as `LastCommentUserID`, d.DateLastComment as `DateLastComment`, if(d.LastCommentID is NULL, d.DiscussionID, d.LastCommentID) as `LastCommentID`,
            d.CountComments as `CountComments`, d.CountViews as `CountViews`, d.Name as `Title`, d.Body as Body, d.Body as DiscussionBody,
            u.Name as `UserName`, u.UserID as `UserID`,
            Lu.Name as `LastUserName`, Lu.UserID as `LastUserID`,
            cat.Name as `CatName`, cat.UrlCode as `CatUrlCode`
            from GDN_Discussion d
            left join GDN_User Lu on Lu.UserID = (IF(d.LastCommentUserID IS NULL, d.InsertUserID, d.LastCommentUserID))
            left join GDN_User u on u.UserID = d.InsertUserID
            inner join GDN_Category cat on cat.CategoryID = d.CategoryID
            where ' . $WhereIn . '
            ')->Result();
        return $this->SortTableResults($Matches, $Comment, $Discussion);
    }

    protected function PrepareFull($Matches) {
        //print_r($Matches); die;
        $SQL = Gdn::SQL();
        $WhereIn = $this->WhereIn('c.CommentID', $Matches['Comment']); //only retrieving comments and the title of the discussion

        $Comment = $SQL->Select('c.DiscussionID, c.InsertUserID, c.Body, c.DateInserted')
                ->Select('d.CountComments, d.CountViews, d.Name as Title,d.Body as DiscussionBody')
                ->Select('u.Name as UserName, u.Photo as UserPhoto, u.UserID')
                ->Select('cat.Name as CatName, cat.UrlCode as CatUrlCode')
                ->From('Comment as c')
                ->Join('Discussion as d', 'c.DiscussionID = d.DiscussionID')
                ->Join('User as u', 'c.InsertUserID = u.UserID')
                ->Join('Category as cat', 'd.CategoryID = cat.CategoryID')
                ->WhereIn('c.CommentID', $Matches['Comment'])
                ->Get()
                ->Result()
        ;
        $Discussion = $SQL->Select('d.DiscussionID, d.InsertUserID, d.Body, d.DateInserted, d.LastCommentUserID')
                ->Select('d.CountComments, d.CountViews, d.Name as Title, d.Body as DiscussionBody')
                ->Select('u.Name as UserName, u.Photo as UserPhoto, u.UserID')
                ->Select('cat.Name as CatName, cat.UrlCode as CatUrlCode')
                ->From('Discussion as d')
                ->Join('User as u', 'd.InsertUserID = u.UserID')
                ->Join('Category as cat', 'd.CategoryID = cat.CategoryID')
                ->WhereIn('d.DiscussionID', $Matches['Discussion'])
                ->Get()
                ->Result()
        ;
        return $this->SortTableResults($Matches, $Comment, $Discussion);
    }

    protected function SortTableResults($Matches, $Comment, $Discussion) {
        $Offset = 0;
        $Return = array();
        foreach ($Matches['Comment'] as $Rating => $ID) {
            $Return[$Rating] = $Comment[$Offset];
            $Offset++;
        }
        $Offset = 0;
        foreach ($Matches['Discussion'] as $Rating => $ID) {
            $Return[$Rating] = $Discussion[$Offset];
            $Offset++;
        }
        ksort($Return); //sort them back in their original ratings again

        return $Return;
    }

    /**
     *
     * Highlights results based on the given string to match results to
     *
     * Cannot use the distributed index for  'BuildExcerpts'...must specify
     * one of its local parts. Since both of the local indexes use the same
     * tokenizing, exceptions, wordforms, N-grams etc, we can just select one
     *
     * @return array
     */
    public function HighLightResults($Results, $Query, $HighlightTitle, $HighlightBody) {
        if (sizeof($Results) == 0)
            return $Results;

        //There are a lot more options that you can do in the sphinx manual
        $BuildExcerptsSettings = array(
            'before_match' => '<span class="SphinxExcerpts">',
            'after_match' => '</span>',
            'limit' => $this->Settings['Admin']->BuildExcerptsLimit,
            'around' => $this->Settings['Admin']->BuildExcerptsAround,
        );

        $TitleArray = array(); //use this array to pass to sphinx
        $BodyArray = array(); //use this array to pass to sphinx
        foreach ($Results as $Row) {
            if (isset($Row->Body))
                $BodyArray[] = $Row->Body;
            if (isset($Row->Title))
                $TitleArray[] = $Row->Title;
        }
        if ($HighlightBody)
            $BodyArray = $this->SphinxClient->BuildExcerpts($BodyArray, SS_INDEX_MAIN, $Query, $BuildExcerptsSettings); //build excerpts
        if ($HighlightTitle)
            $TitleArray = $this->SphinxClient->BuildExcerpts($TitleArray, SS_INDEX_MAIN, $Query, $BuildExcerptsSettings);

        //put results back into original
        $Offset = 0;
        foreach ($Results as $Row) {
            $Row->Body = $BodyArray[$Offset];
            $Row->Title = $TitleArray[$Offset];
            $Offset++;
        }
        //print_r($Result);
        return $Results;
    }

    /**
     *
     * @param string $text
     * @param int $NumMatches number of words to match in the $text
     *
     * Quorum matching operator introduces a kind of fuzzy matching.
     * It will only match those documents that pass a given threshold of given words.
     * The example above ("the world is a wonderful place"/3) will match all documents
     * that have at least 3 of the 6 specified words.
     */
    protected function QuorumSearch($Query, $NumMatches) {
        return '"' . $Query . '/' . $NumMatches . '"';
    }

    protected function PhraseSearch($Query) {
        return '"' . $Query . '"';
    }

    protected function FieldSearch($Query, $Fields = array()) {
        if (sizeof($Fields) == 0)
            return '@(' . $Fields[0] . ') ' . $Query; //single field
        else {
            $Params = '';
            foreach ($Fields as $Field) {
                if ($Params == '')
                    $Params .= $Field;
                else
                    $Params .= ',' . $Field;
            }
            return '@(' . $Params . ') ' . $Query;
        }
    }

    protected function OperatorOrSearch($Query) {
        $Input = '';
        $Return = '';
        if (is_array($Query)) {
            foreach ($Query as $Word)
                $Input .= $Word . ' ';
            $Input = trim($Input);
            $Query = $Input;
        }
        $QueryString = explode(' ', $Query);
        foreach ($QueryString as $Word) {   //add the boolean OR operator (|)
            if (!is_string($Word) || $Word == '')
                continue;
            if ($Return != '')
                $Return .= ' | ' . $Word;
            else
                $Return = $Word;
        }
        return $Return;
    }

    protected function AllSearch($Query) {
        return '@* ' . $Query;
    }

    /**
     * validate the POST from the main search fields
     *
     * @return array result of filtered inputs
     */
    protected function ValidateInputs() {
        $MemberList = array();
        $TagList = array();
        $ForumList = array();
        $Query = '';

        $QueryIn = trim(filter_input(INPUT_GET, 'Search', FILTER_SANITIZE_STRING)); //probably overkill
        $QueryIn = explode(' ', $QueryIn);
        foreach ($QueryIn as $Word) {    //get rid of any white spaces inbetween words
            if (!is_string($Word) || $Word == '')
                continue;
            if ($Query == '')
                $Query = $Word;
            else
                $Query .= ' ' . $Word;
        }

        $TitlesOnly = (filter_input(INPUT_GET, 'titles', FILTER_SANITIZE_NUMBER_INT) == 1 ? 1 : 0); //checkbox - bool
        $WithReplies = (filter_input(INPUT_GET, 'WithReplies', FILTER_SANITIZE_NUMBER_INT) == 1 ? 1 : 0); //checkbox - bool
        $Match = filter_input(INPUT_GET, 'match', FILTER_SANITIZE_STRING);
        $Child = (filter_input(INPUT_GET, 'child', FILTER_SANITIZE_NUMBER_INT) == 1 ? 1 : 0); //checkbox - bool
        if (isset($_GET['forums'])) {
            foreach ($_GET['forums'] as $Forum) {
                if (is_numeric($Forum)) //check if int
                    $ForumList[] = $Forum;
            }
        }
        $Date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING);
        $Order = filter_input(INPUT_GET, 'or', FILTER_SANITIZE_STRING);

        $Members = filter_input(INPUT_GET, 'mem', FILTER_SANITIZE_STRING); //list seperated by comma
        if (!empty($Members)) {
            $Members = explode(',', $Members);
            foreach ($Members as $Member) {
                if (!is_string($Member) || $Member == '' || !str_word_count($Member, 0))
                    continue;
                $MemberList[] = trim($Member);
            }
        }

        $Tags = filter_input(INPUT_GET, 'tag', FILTER_SANITIZE_STRING); //list seperated by comma
        if (!empty($Tags)) {
            $Tags = explode(',', $Tags);
            foreach ($Tags as $Tag) {
                if (!is_string($Tag) || $Tag == '' || !str_word_count($Tag, 0))
                    continue;
                $TagList[] = trim($Tag);
            }
        }
        $SanitizedFormat = filter_input(INPUT_GET, 'res', FILTER_SANITIZE_STRING);
        $Offset = (int) filter_input(INPUT_GET, 'pg', FILTER_SANITIZE_NUMBER_INT);
        if (is_int($Offset))
            $Offset = $Offset;
        else
            $Offset = 0; //default to no offset

        return array(
            'Query' => $Query,
            'TitlesOnly' => $TitlesOnly,
            'WithReplies' => $WithReplies,
            'Match' => $Match,
            'ForumList' => $ForumList,
            'SearchChildren' => $Child,
            'Date' => $Date,
            'MemberList' => $MemberList,
            'Order' => $Order,
            'TagList' => $TagList,
            'Order' => $Order,
            'ResultFormat' => $SanitizedFormat,
            'Offset' => $Offset,
        );
    }

    /**
     * Remove search tags from search keyword
     *
     * @param string $keywords
     * @return string
     */
    protected function ClearFromTags($keywords) {
        $stopWords = array('@title', '@body', '@user', '@(body)', '@(title)', '@(user)', '!', '-', '~', '(', ')', '|',);
        $keywords = trim(str_replace($stopWords, ' ', $keywords));
        if (empty($keywords))
            return '';

        $keyword = trim(preg_replace('/\s+/', ' ', $keywords));

        return $keyword;
    }

    /**
     * Returns a JSON encoded string
     *
     * @param array $Result Sphinx result array
     * @param string $Field field to populate json return string from
     */
    protected function JsonEncode($Result, $Field) {
        $Return = '[';
        foreach ($Result as $Row) {
            if ($Return != '[')
                $Return .= ',';
            $Return .= json_encode(array('value' => GetValue($Field, $Row)));
        }
        $Return .= ']';
        return $Return;
    }

    /**
     *  copy of the class.sqldriver for a convient wherin plain sql query
     *
     * @param type $Field
     * @param type $Values
     * @param type $Op
     * @param type $Escape
     * @return string
     */
    private function WhereIn($Field, $Values, $Op = 'in', $Escape = TRUE) {
        if (is_null($Field) || !is_array($Values))
            return;
        // Build up the in clause.
        $In = array();
        foreach ($Values as $Value) {
            $ValueExpr = (string) $Value;

            if (strlen($ValueExpr) > 0)
                $In[] = $ValueExpr;
        }
        if (count($In) > 0)
            $InExpr = '(' . implode(', ', $In) . ')';
        else
            $InExpr = '(null)';

        // Set the final expression.
        $Expr = $Field . ' ' . $Op . ' ' . $InExpr;
        return $Expr;
    }

}