<?php

namespace Drupal\quizz_text;

use Drupal\quizz\Entity\Result;
use Drupal\quizz_question\Entity\Question;
use Drupal\quizz_question\ResponseHandler;

abstract class TextResponse extends ResponseHandler {

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
  public function submitReportForm(array $form_element_values) {
    /* @var $question Question */
    $result = $form_element_values['result'];
    $question = $form_element_values['question_entity'];
    $answer_feedback = $form_element_values['answer_feedback'];
    $score = (int) $form_element_values['score'];
    $table = 'long_answer' === $question->getQuestionType()->handler ? 'quizz_long_answer' : 'quizz_short_answer';
    return $this->scoreAnAnswer($result, $question, $answer_feedback, $score, $update_total = FALSE, $table);
  }

  private function scoreAnAnswer(Result $result, Question $question, $answer_feedback, $score, $update_total, $table = 'quizz_long_answer') {
    $quiz = $result->getQuiz();
    $answer = $result->loadAnswerByQuestion($question);

    // When we set the score we make sure that the max score in the quiz the
    // question belongs to is considered
    $question_max_score = $question->max_score;

    $quiz_sql = 'SELECT max_score FROM {quiz_relationship} WHERE quiz_vid = :pvid AND question_vid = :cvid';
    $quiz_max_score = db_query($quiz_sql, array(':pvid' => $quiz->vid, ':cvid' => $question->vid))->fetchField();

    $changed = db_update($table)
      ->fields(array(
          'score'                  => $score * $question_max_score / $quiz_max_score,
          'is_evaluated'           => 1,
          'answer_feedback'        => isset($answer_feedback['value']) ? $answer_feedback['value'] : '',
          'answer_feedback_format' => empty($answer_feedback['format']) ? '' : $answer_feedback['format'],
      ))
      ->condition('answer_id', $answer->id)
      ->execute();

    // Now the short answer user data has been updated. We also need to update the
    // data in the quiz tables
    if ($changed > 0) {
      $is_correct = $points_awarded = 0;
      if ($question_max_score > 0) {
        $is_correct = ($score / $question_max_score > 0.5) ? 1 : 0;
        $points_awarded = $score;
      }

      $answer->points_awarded = $points_awarded;
      $answer->is_correct = $is_correct;
      $answer->save();

      // Third, we update the main quiz results table
      $update_total && quizz_result_controller()->getScoreIO()->updateTotalScore($result);
    }

    return $changed;
  }

}
