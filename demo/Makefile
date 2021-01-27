start:
	docker-compose up --no-start
	docker-compose start
	docker exec -it cluster_redis_6390 redis-cli -a redisadmin --cluster create 127.0.0.1:6390 127.0.0.1:6391 127.0.0.1:6392 127.0.0.1:6393 127.0.0.1:6394 127.0.0.1:6395 --cluster-replicas 1

stop:
	docker-compose down
	docker volume rm ydredis_data_6379 ydredis_data_6380 ydredis_data_6381 ydredis_data_6382 ydredis_data_6390 ydredis_data_6391 ydredis_data_6392 ydredis_data_6393 ydredis_data_6394 ydredis_data_6395

