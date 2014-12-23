<?php

namespace Drupal\quizz_text;

use Drupal\quizz\Entity\Answer;
use Drupal\quizz_question\Entity\Question;
use Drupal\quizz_question\ResponseHandler;

class ShortAnswerResponse extends ResponseHandler {

  protected $answer_id = 0;

  /**
   * {@inheritdoc}
   * @var string
   */
  protected $base_table = 'quiz_short_answer_user_answers';

  /** @var bool */
  protected $allow_feedback = TRUE;

  public function __construct($result_id, Question $question, $input = NULL) {
    parent::__construct($result_id, $question, $input);
    if (NULL === $input) {
      if (($answer = $this->loadAnswerEntity()) && ($input = $answer->getInput())) {
        $this->answer = $input->answer;
        $this->score = $input->score;
        $this->evaluated = $input->is_evaluated;
        $this->answer_id = $input->answer_id;
        $this->answer_feedback = $input->answer_feedback;
        $this->answer_feedback_format = $input->answer_feedback_format;
      }
    }
    else {
      if (is_array($input)) {
        $this->answer = $input['answer'];
      }
      else {
        $this->answer = $input;
        $this->evaluated = $this->question->correct_answer_evaluation != ShortAnswerQuestion::ANSWER_MANUAL;
      }
    }
  }

  public function onLoad(Answer $answer) {
    $sql = 'SELECT input.* FROM {quiz_short_answer_user_answers} input WHERE question_vid = :qvid AND result_id = :rid';
    $conds = array(':qvid' => $answer->question_vid, ':rid' => $answer->result_id);
    if ($input = db_query($sql, $conds)->fetchObject()) {
      $answer->setInput($input);
    }
  }

  /**
   * Get all quiz scores that haven't been evaluated yet.
   * @param int $count
   * @param int $offset
   * @return array Array of objects describing unanswered questions. Each object will have result_id, question_qid, and question_vid.
   */
  public static function fetchAllUnscoredAnswers($count = 50, $offset = 0) {
    global $user;

    $query = db_select('quiz_short_answer_user_answers', 'answer');
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
   * Given a question, return a list of all of the unscored answers.
   *
   * @param int $question_qid
   * @param int $question_vid
   * @param int $count
   * @param int $offset
   * @return array Indexed array of result IDs that need to be scored.
   */
  public static function fetchUnscoredAnswersByQuestion($question_qid, $question_vid, $count = 50, $offset = 0) {
    return db_query(
        'SELECT result_id
          FROM {quiz_short_answer_user_answers}
          WHERE is_evaluated = :is_evaluated
            AND question_qid = :question_qid
            AND question_vid = :question_vid', array(
          ':is_evaluated' => 0,
          ':question_qid' => $question_qid,
          ':question_vid' => $question_vid
      ))->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    // We need to set is_evaluated depending on whether the type requires evaluation.
    $this->is_evaluated = (int) ($this->question->correct_answer_evaluation != ShortAnswerQuestion::ANSWER_MANUAL);
    $this->answer_id = db_insert('quiz_short_answer_user_answers')
      ->fields(array(
          'answer'       => $this->answer,
          'question_qid' => $this->question->qid,
          'question_vid' => $this->question->vid,
          'result_id'    => $this->result_id,
          'score'        => $this->getScore(FALSE),
          'is_evaluated' => $this->is_evaluated,
      ))
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function score() {
    // Manual scoring means we go with what is in the DB.
    if ($this->question->correct_answer_evaluation == ShortAnswerQuestion::ANSWER_MANUAL) {
      $score = db_query('SELECT score FROM {quiz_short_answer_user_answers} WHERE result_id = :result_id AND question_vid = :question_vid', array(':result_id' => $this->result_id, ':question_vid' => $this->question->vid))->fetchField();
      if (!$score) {
        $score = 0;
      }
    }
    // Otherwise, we run the scoring automatically.
    else {
      $shortAnswer = new ShortAnswerQuestion($this->question);
      $score = $shortAnswer->evaluateAnswer($this->getResponse());
    }
    return $score;
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
        'solution'          => '',
    );

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getReportFormSubmit() {
    return 'short_answer_report_submit';
  }

  /**
   * {@inheritdoc}
   */
  public function validateReportForm(&$element, &$form_state) {
    // Check to make sure that entered score is not higher than max allowed score.
    if ($element['score']['#value'] > $this->question->max_score) {
      form_error($element['score'], t('The score needs to be a number between 0 and @max', array(
          '@max' => $this->question->max_score
      )));
    }
  }

}
