<?php

namespace Drupal\quizz_text;

use Drupal\quizz\Entity\Answer;
use Drupal\quizz_question\Entity\Question;
use Drupal\quizz_question\ResponseHandler;

/**
 * Extension of QuizQuestionResponse
 */
class LongAnswerResponse extends ResponseHandler {

  /**
   * {@inheritdoc}
   * @var string
   */
  protected $base_table = 'quiz_long_answer_user_answers';

  /** @var int */
  protected $answer_id = 0;

  /** @var bool */
  protected $allow_feedback = TRUE;

  /**
   * Get all scores that have not yet been evaluated.
   *
   * @param $count
   *  Number of items to return (default: 50).
   * @param $offset
   *  Where in the results we should start (default: 0).
   *
   * @return
   *  Array of objects describing unanswered questions. Each object will have result_id, question_qid, and question_vid.
   */
  public static function fetchAllUnscoredAnswers($count = 50, $offset = 0) {
    global $user;

    $query = db_select('quiz_long_answer_user_answers', 'answer');
    $query->fields('answer', array('result_id', 'question_qid', 'question_vid', 'answer_feedback', 'answer_feedback_format'));
    $query->fields('question_revision', array('title'));
    $query->fields('qr', array('time_end', 'time_start', 'uid'));
    $query->join('node_revision', 'question_revision', 'answer.question_vid = question_revision.vid');
    $query->join('quiz_results', 'qr', 'answer.result_id = qr.result_id');
    $query->join('quiz_entity', 'quiz', 'qr.quiz_qid = quiz.qid');
    $query->condition('answer.is_evaluated', 0);

    if (user_access('score own quiz') && user_access('score taken quiz answer')) {
      $query->condition(db_or()->condition('quiz.uid', $user->uid)->condition('qr.uid', $user->uid));
    }
    elseif (user_access('score own quiz')) {
      $query->condition('quiz.uid', $user->uid);
    }
    elseif (user_access('score taken quiz answer')) {
      $query->condition('qr.uid', $user->uid);
    }

    $unscored = array();
    foreach ($query->execute() as $row) {
      $unscored[] = $row;
    }
    return $unscored;
  }

  /**
   * Given a quiz, return a list of all of the unscored answers.
   *
   * @param $qid
   *  Question ID for the quiz to check.
   * @param $vid
   *  Version ID for the quiz to check.
   * @param $count
   *  Number of items to return (default: 50).
   * @param $offset
   *  Where in the results we should start (default: 0).
   *
   * @return
   *  Indexed array of result IDs that need to be scored.
   */
  public static function fetchUnscoredAnswersByQuestion($qid, $vid, $count = 50, $offset = 0) {
    $results = db_query('SELECT result_id FROM {quiz_long_answer_user_answers}
      WHERE is_evaluated = :is_evaluated
      AND question_qid = :question_qid
      AND question_vid = :question_vid', array(
        ':is_evaluated' => 0,
        ':question_qid' => $qid,
        ':question_vid' => $vid
    ));
    $unscored = array();
    foreach ($results as $row) {
      $unscored[] = $row->result_id;
    }
    return $unscored;
  }

  /**
   * Constructor
   */
  public function __construct($result_id, Question $question, $input = NULL) {
    parent::__construct($result_id, $question, $input);

    if (!isset($input)) {
      if (($answer = $this->loadAnswerEntity()) && ($input = $this->loadAnswerEntity()->getInput())) {
        $this->answer = $input->answer;
        $this->score = $input->score;
        $this->evaluated = $input->is_evaluated;
        $this->answer_id = $input->answer_id;
        $this->answer_feedback = $input->answer_feedback;
        $this->answer_feedback_format = $input->answer_feedback_format;
      }
    }
    else {
      $this->answer = $input;
      $this->evaluated = FALSE;
    }
  }

  public function onLoad(Answer $answer) {
    // Question has been answered allready. We fetch the answer data from
    // the database.
    $input = db_select('quiz_long_answer_user_answers', 'input')
      ->fields('input')
      ->condition('question_vid', $answer->question_vid)
      ->condition('result_id', $answer->result_id)
      ->execute()
      ->fetchObject();
    if ($input) {
      $answer->setInput($input);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    db_merge('quiz_long_answer_user_answers')
      ->key(array(
          'question_qid' => $this->question->qid,
          'question_vid' => $this->question->vid,
          'result_id'    => $this->result_id,
      ))
      ->fields(array('answer' => $this->answer))
      ->execute()
    ;
  }

  /**
   * {@inheritdoc}
   */
  public function score() {
    return (int) db_query(
        'SELECT score
          FROM {quiz_long_answer_user_answers}
          WHERE result_id = :result_id AND question_vid = :question_vid', array(
          ':result_id'    => $this->result_id,
          ':question_vid' => $this->question->vid
      ))->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function getFeedbackValues() {
    $data = array();

    $data[] = array(
        'choice'            => '',
        'attempt'           => $this->answer,
        'correct'           => !$this->evaluated ? t('This answer has not yet been scored.') : '',
        'score'             => $this->getScore(),
        'answer_feedback'   => check_markup($this->answer_feedback, $this->answer_feedback_format),
        'question_feedback' => '',
        'solution'          => $this->question->rubric,
    );

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getReportFormSubmit() {
    return 'long_answer_report_submit';
  }

  /**
   * {@inheritdoc}
   */
  public function validateReportForm(&$element, &$form_state) {
    $max = $this->question->max_score;
    // Check to make sure that entered score is not higher than max allowed score.
    if ($element['score']['#value'] > $max) {
      $msg = t('The score needs to be a number between @min and @max', array('@min' => 0, '@max' => $max));
      form_error($element['score'], $msg);
    }
  }

}
