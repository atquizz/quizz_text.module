<?php

namespace Drupal\quizz_text;

use Drupal\quizz_question\QuestionHandler;
use Drupal\quizz_text\LongAnswerResponse;

/**
 * Long answer classes.
 *
 * @file
 *  Classes modelling the long answer question and the long answer question response
 */

/**
 * Extension of QuizQuestion.
 */
class LongAnswerQuestion extends QuestionHandler {

  /**
   * {@inheritdoc}
   */
  public function onSave($is_new = FALSE) {
    if (!isset($this->question->feedback)) {
      $this->question->feedback = '';
    }

    if ($is_new || $this->question->revision == 1) {
      db_insert('quiz_long_answer_properties')
        ->fields(array(
            'qid'    => $this->question->qid,
            'vid'    => $this->question->vid,
            'rubric' => $this->question->rubric,
        ))
        ->execute();
    }
    else {
      db_update('quiz_long_answer_properties')
        ->fields(array('rubric' => isset($this->question->rubric) ? $this->question->rubric : ''))
        ->condition('qid', $this->question->qid)
        ->condition('vid', $this->question->vid)
        ->execute();
    }
  }

  /**
   * Implementation of validateNode
   *
   * @see QuizQuestion#validateNode($form)
   */
  public function validate(array &$form) {

  }

  /**
   * Implementation of delete
   *
   * @see QuizQuestion#delete($only_this_version)
   */
  public function delete($only_this_version = FALSE) {
    if ($only_this_version) {
      db_delete('quiz_long_answer_user_answers')
        ->condition('question_qid', $this->question->qid)
        ->condition('question_vid', $this->question->vid)
        ->execute();
      db_delete('quiz_long_answer_properties')
        ->condition('qid', $this->question->qid)
        ->condition('vid', $this->question->vid)
        ->execute();
    }
    else {
      db_delete('quiz_long_answer_properties')
        ->condition('qid', $this->question->qid)
        ->execute();
      db_delete('quiz_long_answer_user_answers')
        ->condition('question_qid', $this->question->qid)
        ->execute();
    }
    parent::delete($only_this_version);
  }

  /**
   * Implementation of load
   *
   * @see QuizQuestion#load()
   */
  public function load() {
    if (isset($this->properties)) {
      return $this->properties;
    }
    $properties = parent::load();

    $res_a = db_query(
      'SELECT rubric
       FROM {quiz_long_answer_properties}
       WHERE qid = :qid AND vid = :vid', array(
        ':qid' => $this->question->qid,
        ':vid' => $this->question->vid))->fetchAssoc();

    if (is_array($res_a)) {
      $properties = array_merge($properties, $res_a);
    }

    return $this->properties = $properties;
  }

  public function view() {
    $content = parent::view();

    if ($this->viewCanRevealCorrect()) {
      if (!empty($this->question->rubric)) {
        $content['answers'] = array(
            '#type'   => 'item',
            '#title'  => t('Rubric'),
            '#prefix' => '<div class="quiz-solution">',
            '#suffix' => '</div>',
            '#markup' => _filter_autop($this->question->rubric),
            '#weight' => 1,
        );
      }
    }
    else {
      $content['answers'] = array(
          '#markup' => '<div class="quiz-answer-hidden">Answer hidden</div>',
          '#weight' => 1,
      );
    }

    return $content;
  }

  /**
   * Implementation of getAnweringForm
   *
   * @see QuizQuestion#getAnsweringForm($form_state, $result_id)
   */
  public function getAnsweringForm(array $form_state = NULL, $result_id) {
    $element = parent::getAnsweringForm($form_state, $result_id);

    $element += array(
        '#type'        => 'textarea',
        '#title'       => t('Answer'),
        '#description' => t('Enter your answer here. If you need more space, click on the grey bar at the bottom of this area and drag it down.'),
        '#rows'        => 15,
        '#cols'        => 60,
    );

    if (isset($result_id)) {
      $response = new LongAnswerResponse($result_id, $this->question);
      $element['#default_value'] = $response->getResponse();
    }

    return $element;
  }

  /**
   * Question response validator.
   */
  public function validateAnsweringForm(array &$form, array &$form_state = NULL) {
    if ($form_state['values']['question'][$this->question->qid]['answer'] == '') {
      form_set_error('', t('You must provide an answer.'));
    }
  }

  /**
   * Implementation of getCreationForm
   *
   * @see QuizQuestion#getCreationForm($form_state)
   */
  public function getCreationForm(array &$form_state = NULL) {
    $form['rubric'] = array(
        '#type'          => 'textarea',
        '#title'         => t('Rubric'),
        '#description'   => t('Specify the criteria for grading the response.'),
        '#default_value' => isset($this->question->rubric) ? $this->question->rubric : '',
        '#size'          => 60,
        '#maxlength'     => 2048,
        '#required'      => FALSE,
    );
    return $form;
  }

  /**
   * Implementation of getMaximumScore
   *
   * @see QuizQuestion#getMaximumScore()
   */
  public function getMaximumScore() {
    return $this->question->getQuestionType()->getConfig('long_answer_default_max_score', 10);
  }

}
