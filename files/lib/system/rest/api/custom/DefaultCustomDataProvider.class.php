<?php

namespace wcf\system\rest\api\custom;

use wcf\data\conversation\Conversation;
use wcf\data\conversation\message\ConversationMessage;
use wcf\data\conversation\message\ViewableConversationMessageList;
use wcf\data\conversation\UserConversationList;
use wcf\data\package\PackageCache;
use wcf\data\user\User;
use wcf\data\user\UserProfile;
use wcf\system\exception\AJAXException;
use wcf\system\user\notification\UserNotificationHandler;
use wcf\system\WCF;

/**
 * @author	Florian Gail
 * @copyright	2017 Florian Gail <https://www.mysterycode.de>
 * @license	GNU Lesser General Public License <http://www.gnu.org/licenses/lgpl-3.0.txt>
 * @package	de.codequake.wcf.rest
 */
class DefaultCustomDataProvider {
	/**
	 * @return mixed[][]
	 */
	public static function getNotifications() {
		$notifications = UserNotificationHandler::getInstance()->getMixedNotifications();
		
		$data = [];
		foreach ($notifications['notifications'] as $notification) {
			/** @var \wcf\system\user\notification\event\IUserNotificationEvent $event */
			$event = $notification['event'];
			
			$authorList = [];
			foreach ($event->getAuthors() as $author) {
				$authorList[] = $author->getUsername();
			}
			$authors = implode(", ", $authorList);
			
			$avatar = $event->getAuthor()->getAvatar();
			
			$data[] = [
				'authorCount' => $notification['authors'],
				'notificationID' => $notification['notificationID'],
				'time' => $notification['time'],
				'title' => $event->getTitle(),
				'message' => $event->getMessage(),
				'isVisible' => $event->isVisible(),
				'isAccessible' => $event->checkAccess(),
				'eventHash' => $event->getEventHash(),
				'isConfirmed' => $event->isConfirmed(),
				'url' => $event->getLink(),
				'authors' => $authors ?: $event->getAuthor()->getUsername(),
				'avatarUrl' => $avatar->getURL()
			];
		}
		
		return [
			'notifications' => $data
		];
	}
	
	/**
	 * @return mixed[][]
	 */
	public static function getUnreadNotifications() {
		$notifications = UserNotificationHandler::getInstance()->getNotifications();
		
		$data = [];
		foreach ($notifications['notifications'] as $notification) {
			/** @var \wcf\system\user\notification\event\IUserNotificationEvent $event */
			$event = $notification['event'];
			
			if ($event->isConfirmed()) {
				continue;
			}
			
			$authorList = [];
			foreach ($event->getAuthors() as $author) {
				$authorList[] = $author->getUsername();
			}
			$authors = implode(", ", $authorList);
			
			$avatar = $event->getAuthor()->getAvatar();
			
			$data[] = [
				'authorCount' => $notification['authors'],
				'notificationID' => $notification['notificationID'],
				'time' => $notification['time'],
				'title' => $event->getTitle(),
				'message' => $event->getMessage(),
				'isVisible' => $event->isVisible(),
				'isAccessible' => $event->checkAccess(),
				'eventHash' => $event->getEventHash(),
				'isConfirmed' => $event->isConfirmed(),
				'url' => $event->getLink(),
				'authors' => $authors ?: $event->getAuthor()->getUsername(),
				'avatarUrl' => $avatar->getURL()
			];
		}
		
		return [
			'notifications' => $data
		];
	}
	
	/**
	 * @return mixed[][]
	 */
	public static function getConversations() {
		if (PackageCache::getInstance()->getPackageByIdentifier('com.woltlab.wcf.conversation') === null) {
			return [];
		}
		
		$sqlSelect = '  , (SELECT participantID FROM wcf'.WCF_N.'_conversation_to_user WHERE conversationID = conversation.conversationID AND participantID <> conversation.userID AND isInvisible = 0 ORDER BY username, participantID LIMIT 1) AS otherParticipantID
				, (SELECT username FROM wcf'.WCF_N.'_conversation_to_user WHERE conversationID = conversation.conversationID AND participantID <> conversation.userID AND isInvisible = 0 ORDER BY username, participantID LIMIT 1) AS otherParticipant';
		
		$unreadConversationList = new UserConversationList(WCF::getUser()->userID);
		$unreadConversationList->sqlSelects .= $sqlSelect;
		$unreadConversationList->getConditionBuilder()->add('conversation_to_user.lastVisitTime < conversation.lastPostTime');
		$unreadConversationList->sqlLimit = 10;
		$unreadConversationList->sqlOrderBy = 'conversation.lastPostTime DESC';
		$unreadConversationList->readObjects();
		
		$conversations = [];
		$count = 0;
		foreach ($unreadConversationList as $conversation) {
			$conversation->setFirstMessage(new ConversationMessage($conversation->firstMessageID));
			$userProfile = new UserProfile(new User($conversation->getFirstMessage()->userID));
			
			$conversations[] = [
				'conversation' => $conversation,
				'firstUser' => $userProfile,
				'avatarUrl' => $userProfile->getAvatar()->getURL(),
				'firstMessageExcerpt' => $conversation->getFirstMessage()->getExcerpt(),
				'lastUser' => $conversation->getLastPosterProfile(),
				'lastUserAvatarUrl' => $conversation->getLastPosterProfile()->getAvatar()->getURL(),
				'participants' => implode(", ", $conversation->getParticipantNames(true)),
				'conversationID' => $conversation->conversationID,
				'lastPostTime' => $conversation->lastPostTime
			];
			$count++;
		}
		
		if ($count < 10) {
			$conversationList = new UserConversationList(WCF::getUser()->userID);
			$conversationList->sqlSelects .= $sqlSelect;
			$conversationList->getConditionBuilder()->add('conversation_to_user.lastVisitTime >= conversation.lastPostTime');
			$conversationList->sqlLimit = (10 - $count);
			$conversationList->sqlOrderBy = 'conversation.lastPostTime DESC';
			$conversationList->readObjects();
			
			foreach ($conversationList as $conversation) {
				$conversation->setFirstMessage(new ConversationMessage($conversation->firstMessageID));
				$userProfile = new UserProfile(new User($conversation->getFirstMessage()->userID));
				
				$conversations[] = [
					'conversation' => $conversation,
					'firstUser' => $userProfile,
					'avatarUrl' => $userProfile->getAvatar()->getURL(),
					'firstMessageExcerpt' => $conversation->getFirstMessage()->getExcerpt(),
					'lastUser' => $conversation->getLastPosterProfile(),
					'lastUserAvatarUrl' => $conversation->getLastPosterProfile()->getAvatar()->getURL(),
					'participants' => implode(", ", $conversation->getParticipantNames(true)),
					'conversationID' => $conversation->conversationID,
					'lastPostTime' => $conversation->lastPostTime
				];
			}
		}
		
		return [
			'conversations' => $conversations
		];
	}
	
	/**
	 * @return mixed[][]
	 */
	public static function getUnreadConversations() {
		if (PackageCache::getInstance()->getPackageByIdentifier('com.woltlab.wcf.conversation') === null) {
			return [];
		}
		
		$sqlSelect = '  , (SELECT participantID FROM wcf'.WCF_N.'_conversation_to_user WHERE conversationID = conversation.conversationID AND participantID <> conversation.userID AND isInvisible = 0 ORDER BY username, participantID LIMIT 1) AS otherParticipantID
				, (SELECT username FROM wcf'.WCF_N.'_conversation_to_user WHERE conversationID = conversation.conversationID AND participantID <> conversation.userID AND isInvisible = 0 ORDER BY username, participantID LIMIT 1) AS otherParticipant';
		
		$unreadConversationList = new UserConversationList(WCF::getUser()->userID);
		$unreadConversationList->sqlSelects .= $sqlSelect;
		$unreadConversationList->getConditionBuilder()->add('conversation_to_user.lastVisitTime < conversation.lastPostTime');
		$unreadConversationList->sqlLimit = 10;
		$unreadConversationList->sqlOrderBy = 'conversation.lastPostTime DESC';
		$unreadConversationList->readObjects();
		
		$conversations = [];
		foreach ($unreadConversationList as $conversation) {
			$conversation->setFirstMessage(new ConversationMessage($conversation->firstMessageID));
			$userProfile = new UserProfile(new User($conversation->getFirstMessage()->userID));
			
			$conversations[] = [
				'conversation' => $conversation,
				'firstUser' => $userProfile,
				'avatarUrl' => $userProfile->getAvatar()->getURL(),
				'firstMessageExcerpt' => $conversation->getFirstMessage()->getExcerpt(),
				'lastUser' => $conversation->getLastPosterProfile(),
				'lastUserAvatarUrl' => $conversation->getLastPosterProfile()->getAvatar()->getURL(),
				'participants' => implode(", ", $conversation->getParticipantNames(true)),
				'conversationID' => $conversation->conversationID,
				'lastPostTime' => $conversation->lastPostTime
			];
		}
		
		return [
			'conversations' => $conversations
		];
	}
	
	/**
	 * @param integer $conversationID
	 * @param string  $sortOrder
	 * @return mixed[][]
	 * @throws \wcf\system\exception\AJAXException
	 */
	public static function getConversationMessageList($conversationID, $sortOrder = 'DESC') {
		if (PackageCache::getInstance()->getPackageByIdentifier('com.woltlab.wcf.conversation') === null) {
			return [];
		}
		
		$conversation = Conversation::getUserConversation(intval($conversationID), WCF::getUser()->userID);
		
		if ($conversation === null || !$conversation->conversationID) {
			throw new AJAXException('conversation cannot be found', AJAXException::BAD_PARAMETERS);
		}
		
		if (!$conversation->canRead()) {
			throw new AJAXException('user is not allowed to read the conversation', AJAXException::INSUFFICIENT_PERMISSIONS);
		}
		
		$messages = [];
		
		$messageList = new ViewableConversationMessageList();
		$messageList->getConditionBuilder()->add('conversation_message.conversationID = ?', [$conversation->conversationID]);
		$messageList->sqlOrderBy = $messageList->sqlOrderBy . ' ' . $sortOrder;
		$messageList->readObjects();
		
		foreach ($messageList->getObjects() as $message) {
			$messages[] = [
				'message' => $message,
				'processedMessage' => $message->getFormattedMessage(),
				'simplifiedMessage' => $message->getSimplifiedFormattedMessage(),
				'username' => $message->getUserProfile()->getFormattedUsername(),
				'user' => $message->getUserProfile(),
				'avatarUrl' => $message->getUserProfile()->getAvatar()->getURL()
			];
		}
		
		return [
			'conversation' => $conversation,
			'messages' => $messages
		];
	}
}
