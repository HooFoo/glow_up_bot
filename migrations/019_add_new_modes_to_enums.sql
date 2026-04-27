-- Update ENUMs for new modes
ALTER TABLE users MODIFY COLUMN active_mode ENUM('nutrition', 'cosmetics', 'coach', 'beauty_assistant', 'practices') DEFAULT NULL;
ALTER TABLE messages MODIFY COLUMN mode ENUM('nutrition', 'cosmetics', 'coach', 'quiz', 'beauty_assistant', 'practices') DEFAULT NULL;
ALTER TABLE conversation_summaries MODIFY COLUMN mode ENUM('nutrition', 'cosmetics', 'coach', 'general', 'beauty_assistant', 'practices') NOT NULL;
